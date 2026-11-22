<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SubscriptionsEarningsExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(): User
    {
        $u = User::factory()->create();
        $u->regenerateApiCredentials();
        $u->regenerateSandboxApiCredentials();
        $u->paymentAccounts()->create(['provider' => 'manual', 'label' => 'Main', 'upi_id' => 'shop@upi', 'payee_name' => 'Shop', 'is_active' => true, 'is_default' => true]);

        return $u;
    }

    private function sub(User $u, array $o = []): Subscription
    {
        return $u->subscriptions()->create($o + ['plan_name' => 'Tuition', 'amount' => 500, 'interval' => 'monthly', 'customer_name' => 'Asha', 'customer_email' => 'asha@example.com', 'next_due_at' => today()]);
    }

    public function test_due_subscription_is_invoiced_once_with_long_expiry_and_email(): void
    {
        $u = $this->merchant();
        $s = $this->sub($u);

        $this->artisan('subscriptions:bill')->expectsOutput('Created 1 invoices.')->assertSuccessful();
        $this->artisan('subscriptions:bill')->expectsOutput('Created 0 invoices.')->assertSuccessful();

        $order = Order::firstOrFail();
        $this->assertSame('500.00', $order->amount);
        $this->assertTrue($order->expires_at->gt(now()->addDays(2)));
        $this->assertTrue($s->fresh()->next_due_at->gt(today()));
        $this->assertSame($order->id, $s->fresh()->last_order_id);
        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString($order->order_no, $sent[0]->getOriginalMessage()->toString());
    }

    public function test_long_overdue_subscription_is_billed_once_not_per_missed_cycle(): void
    {
        Mail::fake();
        $u = $this->merchant();
        $s = $this->sub($u, ['next_due_at' => today()->subMonths(5)]);
        $this->artisan('subscriptions:bill')->assertSuccessful();
        $this->assertSame(1, Order::count());
        $this->assertTrue($s->fresh()->next_due_at->gt(today()));
    }

    public function test_paused_cancelled_and_inactive_merchant_are_not_billed(): void
    {
        Mail::fake();
        $u = $this->merchant();
        $this->sub($u, ['status' => 'paused']);
        $this->sub($u, ['status' => 'cancelled']);
        $off = $this->merchant();
        $off->update(['is_active' => false]);
        $this->sub($off);
        $this->artisan('subscriptions:bill')->assertSuccessful();
        $this->assertSame(0, Order::count());
    }

    public function test_month_end_does_not_overflow(): void
    {
        $s = new Subscription(['interval' => 'monthly']);
        $this->assertSame('2027-02-28', $s->advance(\Carbon\Carbon::parse('2027-01-31'))->format('Y-m-d'));
    }

    public function test_subscription_management_is_scoped_to_owner(): void
    {
        $a = $this->merchant();
        $b = $this->merchant();
        $s = $this->sub($a);
        $this->actingAs($b)->post("/subscriptions/{$s->id}", ['action' => 'cancel'])->assertNotFound();
        $this->actingAs($b)->post("/subscriptions/{$s->id}/bill")->assertNotFound();
        $this->actingAs($a)->post("/subscriptions/{$s->id}", ['action' => 'pause'])->assertSessionHasNoErrors();
        $this->assertSame('paused', $s->fresh()->status);
        $this->actingAs($a)->post('/subscriptions', ['plan_name' => 'P', 'amount' => 10, 'interval' => 'weekly', 'customer_name' => 'X', 'first_due' => today()->format('Y-m-d'), 'payment_account_id' => $b->paymentAccounts()->first()->id])
            ->assertSessionHasErrors('payment_account_id');
    }

    public function test_earnings_ledger_nets_refunds_and_is_scoped(): void
    {
        $a = $this->merchant();
        $b = $this->merchant();
        $svc = app(OrderService::class);
        $o = $svc->markPaid($svc->create($a, ['amount' => 300, 'client_order_id' => 'E1']), 'UTR111111111', null, [], 'manual');
        $svc->refund($o, 50, 'partial');
        $svc->markPaid($svc->create($b, ['amount' => 9999, 'client_order_id' => 'E2']), 'UTR222222222', null, [], 'manual');

        $this->actingAs($a)->get('/earnings')->assertOk()->assertSee('250.00')->assertDontSee('9,999');
        $this->actingAs($a)->get('/earnings?account='.$b->paymentAccounts()->first()->id)->assertNotFound();
    }

    public function test_sandbox_simulate_via_api_and_live_key_rejected(): void
    {
        $u = $this->merchant();
        $sandbox = $this->withHeaders(['X-Api-Key' => $u->sandbox_api_key, 'X-Api-Secret' => $u->sandbox_api_secret]);
        $created = $sandbox->postJson('/api/v1/orders', ['amount' => 20, 'client_order_id' => 'T1'])->assertCreated()->json('data.order_id');

        $sandbox->postJson("/api/v1/orders/{$created}/simulate", ['outcome' => 'success'])->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.test', true);

        $live = $this->withHeaders(['X-Api-Key' => $u->api_key, 'X-Api-Secret' => $u->api_secret]);
        $liveId = $live->postJson('/api/v1/orders', ['amount' => 20, 'client_order_id' => 'L1'])->assertCreated()->json('data.order_id');
        $live->postJson("/api/v1/orders/{$liveId}/simulate")->assertForbidden();
        $this->assertSame('pending', Order::where('order_no', $liveId)->value('status'));
    }

    public function test_receipt_only_for_paid_live_orders(): void
    {
        $u = $this->merchant();
        $svc = app(OrderService::class);
        $o = $svc->create($u, ['amount' => 75, 'client_order_id' => 'RC']);
        $this->get("/pay/{$o->order_no}/receipt")->assertNotFound();
        $svc->markPaid($o, 'UTR333333333', null, [], 'manual');
        $this->get("/pay/{$o->order_no}/receipt")->assertOk()->assertSee('Payment received')->assertSee('75.00');
        $this->get("/pay/{$o->order_no}")->assertSee('Download receipt');
    }

    public function test_pay_page_has_last_used_app_hooks(): void
    {
        $u = $this->merchant();
        $o = app(OrderService::class)->create($u, ['amount' => 75, 'client_order_id' => 'PP']);
        $this->get("/pay/{$o->order_no}")->assertOk()->assertSee('data-upi-app="GPay"', false)->assertSee('emudra_last_app');
    }

    public function test_integration_downloads_inject_gateway_url_and_docs_list_them(): void
    {
        config(['app.url' => 'https://pay.example.com']);
        foreach (['php', 'node', 'woocommerce'] as $kind) {
            $this->get("/integrations/$kind")->assertOk()->assertSee('https://pay.example.com', false)->assertDontSee('__GATEWAY_URL__', false);
        }
        $this->get('/integrations/other')->assertNotFound();
        $this->get('/docs')->assertOk()->assertSee('WooCommerce')->assertSee('/simulate', false);
    }
}
