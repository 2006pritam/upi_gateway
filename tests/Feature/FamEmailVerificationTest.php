<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\EmailPayment;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Services\Email\FamAppEmailParser;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FamEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    private PaymentAccount $account;

    private const AUTH = 'mx.google.com; dkim=pass header.i=@famapp.in header.s=s1; spf=pass smtp.mailfrom=famapp.in; dmarc=pass (p=REJECT) header.from=famapp.in';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->merchant = User::create(['name' => 'Ravi', 'business_name' => 'Ravi Store', 'email' => 'ravi@example.com', 'password' => 'password123']);
        $this->account = $this->merchant->paymentAccounts()->create([
            'provider' => 'fam_email', 'label' => 'Fam', 'upi_id' => 'ravi@famg', 'payee_name' => 'Ravi',
            'credentials' => ['ingest_secret' => 'secret-secret-secret'],
        ]);
    }

    private function order(float $amount = 499, array $extra = []): Order
    {
        return app(OrderService::class)->create($this->merchant, ['amount' => $amount] + $extra);
    }

    private function mail(float $amount = 499, string $utr = '123456789012', string $purpose = '', ?string $id = null, array $over = []): array
    {
        $amt = number_format($amount, 2, '.', '');

        return $over + [
            'message_id' => $id ?? 'msg-'.$utr,
            'from' => 'FamApp <no-reply@famapp.in>',
            'subject' => "You received ₹{$amt} in your FamX account",
            'body' => "Hi, you have successfully received ₹{$amt} from JOHN DOE. UTR: {$utr}. Transaction ID: FMPIB0000000001. Purpose: {$purpose}. If this was not done by you, contact support.",
            'received_at' => now()->toIso8601String(),
            'auth_results' => self::AUTH,
        ];
    }

    private function ingest(array $mail, ?string $secret = null, ?int $ts = null, ?int $account = null)
    {
        $body = json_encode($mail);
        $ts ??= time();
        $sig = hash_hmac('sha256', $ts.'.'.$body, $secret ?? 'secret-secret-secret');

        return $this->call('POST', route('api.email-ingest', $account ?? $this->account->id), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TIMESTAMP' => (string) $ts,
            'HTTP_X_SIGNATURE' => $sig,
        ], $body);
    }

    public function test_parser_reads_real_format(): void
    {
        $m = $this->mail(499, '123456789012', 'ORD123');
        $p = app(FamAppEmailParser::class)->parse($m['subject'], $m['body']);
        $this->assertSame('123456789012', $p->utr);
        $this->assertEquals(499.0, (float) $p->amount);
        $this->assertSame('ORD123', $p->purpose);
        $this->assertNull(app(FamAppEmailParser::class)->parse('Hello', 'nothing'));
    }

    public function test_unique_amount_order_is_paid(): void
    {
        $order = $this->order(499);
        $this->ingest($this->mail(499))->assertOk()->assertJson(['status' => 'matched']);
        $order->refresh();
        $this->assertSame(Order::PAID, $order->status);
        $this->assertSame('email', $order->verified_by);
        $this->assertSame('123456789012', $order->utr);
        Queue::assertPushed(SendWebhook::class, fn ($j) => $j->event === 'order.paid');
    }

    public function test_replay_and_duplicates_are_idempotent(): void
    {
        $order = $this->order(499);
        $mail = $this->mail(499);
        $this->ingest($mail)->assertJson(['status' => 'matched']);
        $this->ingest($mail)->assertJson(['status' => 'duplicate']);
        $this->assertSame(1, EmailPayment::count());
        $this->assertSame(Order::PAID, $order->fresh()->status);
    }

    public function test_bad_signature_wrong_secret_and_stale_timestamp_get_401(): void
    {
        $this->order(499);
        $this->ingest($this->mail(499), 'wrong')->assertStatus(401);
        $this->ingest($this->mail(499), null, time() - 1000)->assertStatus(401);
        $this->ingest($this->mail(499), null, null, 99999)->assertStatus(401);
        $this->assertSame(0, EmailPayment::count());
    }

    public function test_forged_mail_without_auth_pass_is_rejected(): void
    {
        $order = $this->order(499);
        $this->ingest($this->mail(499, over: ['auth_results' => 'mx.google.com; dkim=fail header.i=@famapp.in; dmarc=fail header.from=famapp.in']))
            ->assertJson(['status' => 'rejected']);
        $this->ingest($this->mail(499, '999999999999', over: ['auth_results' => 'mx.google.com; dkim=pass header.i=@evil.com; dmarc=pass header.from=evil.com']))
            ->assertJson(['status' => 'rejected']);
        $this->ingest($this->mail(499, '888888888888', over: ['from' => 'x <no-reply@evil.com>']))->assertJson(['status' => 'ignored']);
        $this->assertSame(Order::PENDING, $order->fresh()->status);
    }

    public function test_purpose_picks_the_order_among_same_amounts(): void
    {
        $a = $this->order(100);
        $b = $this->order(100);
        $this->ingest($this->mail(100, purpose: $b->order_no))->assertJson(['status' => 'matched']);
        $this->assertSame(Order::PENDING, $a->fresh()->status);
        $this->assertSame(Order::PAID, $b->fresh()->status);
    }

    public function test_same_amount_without_note_is_ambiguous_and_not_guessed(): void
    {
        $a = $this->order(100);
        $b = $this->order(100);
        $this->ingest($this->mail(100))->assertJson(['status' => 'ambiguous']);
        $this->assertSame(Order::PENDING, $a->fresh()->status);
        $this->assertSame(Order::PENDING, $b->fresh()->status);
    }

    public function test_purpose_naming_other_order_is_unmatched(): void
    {
        $a = $this->order(100);
        $this->ingest($this->mail(100, purpose: 'ORDZZZZZZZZZZZZZZZZ'))->assertJson(['status' => 'unmatched']);
        $this->assertSame(Order::PENDING, $a->fresh()->status);
    }

    public function test_amount_mismatch_is_unmatched(): void
    {
        $a = $this->order(100);
        $this->ingest($this->mail(101))->assertJson(['status' => 'unmatched']);
        $this->assertSame(Order::PENDING, $a->fresh()->status);
    }

    public function test_payment_before_order_creation_does_not_pay_it(): void
    {
        $old = now()->subHour()->toIso8601String();
        $a = $this->order(100);
        $this->ingest($this->mail(100, over: ['received_at' => $old]))
            ->assertJson(['status' => 'unmatched']);
        $this->assertSame(Order::PENDING, $a->fresh()->status);
    }

    public function test_customer_utr_reconciles_unmatched_payment(): void
    {
        $a = $this->order(100);
        $b = $this->order(100);
        $this->ingest($this->mail(100, '555555555555'))->assertJson(['status' => 'ambiguous']);

        $this->post(route('pay.utr', $b), ['utr' => '555555555555'])->assertRedirect();
        $this->assertSame(Order::PAID, $b->fresh()->status);
        $this->assertSame(Order::PENDING, $a->fresh()->status);
    }

    public function test_setup_page_is_owner_only_and_new_account_has_secret(): void
    {
        $this->actingAs($this->merchant)->get(route('accounts.email-setup', $this->account))
            ->assertOk()->assertSee('secret-secret-secret', false);

    }

    public function test_setup_page_rejects_other_merchants(): void
    {
        $other = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'password123']);
        $this->actingAs($other)->get(route('accounts.email-setup', $this->account))->assertForbidden();
    }

    public function test_new_fam_account_gets_ingest_secret(): void
    {
        $this->actingAs($this->merchant)->post(route('accounts.store'), [
            'provider' => 'fam_email', 'label' => 'F2', 'upi_id' => 'ab@famg', 'payee_name' => 'A',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $new = PaymentAccount::where('label', 'F2')->first();
        $this->assertNotEmpty($new->credentials['ingest_secret'] ?? null);
    }
}
