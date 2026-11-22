<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\PaymentLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentLinksAlertsReportsTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(): User
    {
        $u = User::factory()->create();
        $u->regenerateApiCredentials();
        $u->paymentAccounts()->create(['provider' => 'manual', 'label' => 'Main', 'upi_id' => 'shop@upi', 'payee_name' => 'Shop', 'is_active' => true, 'is_default' => true]);

        return $u;
    }

    public function test_merchant_creates_link_and_customer_gets_fresh_order_each_time(): void
    {
        $u = $this->merchant();
        $this->actingAs($u)->post('/links', ['title' => 'Course fee', 'amount' => '499'])->assertSessionHasNoErrors();
        $link = $u->paymentLinks()->firstOrFail();

        $this->get("/p/{$link->code}")->assertOk()->assertSee('Course fee')->assertSee('499.00');
        $r1 = $this->post("/p/{$link->code}", ['customer_name' => 'A']);
        $r2 = $this->post("/p/{$link->code}", ['customer_name' => 'B']);
        $this->assertSame(2, Order::where('payment_link_id', $link->id)->count());
        $r1->assertRedirect();
        $this->assertNotSame($r1->headers->get('Location'), $r2->headers->get('Location'));
        $this->assertSame('499.00', Order::first()->amount);
    }

    public function test_open_amount_link_requires_amount_and_fixed_link_rejects_override(): void
    {
        $u = $this->merchant();
        $open = $u->paymentLinks()->create(['title' => 'Tips']);
        $fixed = $u->paymentLinks()->create(['title' => 'Fee', 'amount' => 100]);

        $this->post("/p/{$open->code}", [])->assertSessionHasErrors('amount');
        $this->post("/p/{$open->code}", ['amount' => '25.50'])->assertRedirect();
        $this->assertSame('25.50', Order::first()->amount);
        $this->post("/p/{$fixed->code}", ['amount' => '1'])->assertSessionHasErrors('amount');
    }

    public function test_disabled_link_and_inactive_merchant_are_404(): void
    {
        $u = $this->merchant();
        $link = $u->paymentLinks()->create(['title' => 'X', 'amount' => 10, 'is_active' => false]);
        $this->get("/p/{$link->code}")->assertNotFound();
        $link->update(['is_active' => true]);
        $u->update(['is_active' => false]);
        $this->get("/p/{$link->code}")->assertNotFound();
    }

    public function test_merchant_cannot_touch_another_merchants_link(): void
    {
        $a = $this->merchant();
        $b = $this->merchant();
        $link = $a->paymentLinks()->create(['title' => 'A', 'amount' => 10]);
        $this->actingAs($b)->get("/links/{$link->code}/poster")->assertNotFound();
        $this->actingAs($b)->post("/links/{$link->code}/toggle")->assertNotFound();
        $this->actingAs($b)->delete("/links/{$link->code}")->assertNotFound();
        $this->actingAs($a)->get("/links/{$link->code}/poster")->assertOk()->assertSee('Scan to pay')->assertSee('<svg', false);
    }

    public function test_link_cannot_point_at_another_merchants_account(): void
    {
        $a = $this->merchant();
        $b = $this->merchant();
        $this->actingAs($a)->post('/links', ['title' => 'X', 'amount' => 5, 'payment_account_id' => $b->paymentAccounts()->first()->id])
            ->assertSessionHasErrors('payment_account_id');
        $this->assertSame(0, PaymentLink::count());
    }

    public function test_telegram_alert_sent_when_paid_and_only_when_enabled(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $u = $this->merchant();
        $order = app(\App\Services\OrderService::class)->create($u, ['amount' => 50, 'client_order_id' => 'C1', 'customer_name' => 'Ravi']);

        app(\App\Services\OrderService::class)->markPaid($order, 'UTR123456789', null, [], 'manual');
        Http::assertNothingSent();

        $u->update(['telegram_bot_token' => '123456789:'.str_repeat('A', 35), 'telegram_chat_id' => '42', 'telegram_alerts' => true]);
        $o2 = app(\App\Services\OrderService::class)->create($u->fresh(), ['amount' => 60, 'client_order_id' => 'C2', 'customer_name' => 'Ravi']);
        app(\App\Services\OrderService::class)->markPaid($o2, 'UTR987654321', null, [], 'manual');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.telegram.org/bot123456789:') && str_contains($r['text'], '60') && $r['chat_id'] === '42');
    }

    public function test_alert_settings_validate_token_and_never_expose_it(): void
    {
        $u = $this->merchant();
        $this->actingAs($u)->post('/settings/alerts', ['telegram_bot_token' => 'bad', 'telegram_chat_id' => '1'])->assertSessionHasErrors('telegram_bot_token');
        $token = '123456789:'.str_repeat('B', 35);
        $this->actingAs($u)->post('/settings/alerts', ['telegram_bot_token' => $token, 'telegram_chat_id' => '99', 'telegram_alerts' => '1', 'daily_summary' => '1'])->assertSessionHasNoErrors();
        $u->refresh();
        $this->assertTrue($u->telegram_alerts);
        $this->assertTrue($u->daily_summary);
        $this->actingAs($u)->get('/settings')->assertOk()->assertDontSee($token);
        $this->assertStringNotContainsString($token, \DB::table('users')->where('id', $u->id)->value('telegram_bot_token'));
    }

    public function test_reports_summary_and_csv_escape_formulas_and_scope_to_merchant(): void
    {
        $a = $this->merchant();
        $b = $this->merchant();
        $svc = app(\App\Services\OrderService::class);
        $o = $svc->create($a, ['amount' => 200, 'client_order_id' => 'R1', 'customer_name' => '=HYPERLINK("http://x")']);
        $svc->markPaid($o, 'UTR111111111', null, [], 'manual');
        $svc->create($b, ['amount' => 999, 'client_order_id' => 'B1']);

        $this->actingAs($a)->get('/reports')->assertOk()->assertSee('200.00')->assertDontSee('999.00');
        $csv = $this->actingAs($a)->get('/reports/export.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('B1', $csv);
        $this->actingAs($a)->get('/reports?from=bad')->assertSessionHasErrors('from');
    }

    public function test_daily_summary_command_mails_only_opted_in_merchants(): void
    {
        $a = $this->merchant();
        $a->update(['daily_summary' => true]);
        $this->merchant();
        \Illuminate\Support\Facades\Mail::fake();
        $this->artisan('reports:daily-summary')->expectsOutput('Sent 1 summaries.')->assertSuccessful();
    }
}
