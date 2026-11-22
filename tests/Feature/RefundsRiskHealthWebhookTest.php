<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RefundsRiskHealthWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(): User
    {
        $u = User::factory()->create();
        $u->regenerateApiCredentials();
        $u->paymentAccounts()->create(['provider' => 'manual', 'label' => 'Main', 'upi_id' => 'shop@upi', 'payee_name' => 'Shop', 'is_active' => true, 'is_default' => true]);

        return $u;
    }

    private function paidOrder(User $u, float $amount = 100, string $cid = 'O1'): Order
    {
        $svc = app(OrderService::class);

        return $svc->markPaid($svc->create($u, ['amount' => $amount, 'client_order_id' => $cid]), 'UTR'.random_int(100000000, 999999999), null, [], 'manual');
    }

    public function test_partial_refunds_are_capped_at_the_paid_amount(): void
    {
        $u = $this->merchant();
        $order = $this->paidOrder($u, 100);

        $this->actingAs($u)->post("/orders/{$order->order_no}/refund", ['amount' => '30', 'reason' => 'Damaged'])->assertSessionHasNoErrors();
        $this->actingAs($u)->post("/orders/{$order->order_no}/refund", ['amount' => '70.01', 'reason' => 'Too much'])->assertSessionHasErrors('amount');
        $this->actingAs($u)->post("/orders/{$order->order_no}/refund", ['amount' => '70', 'reason' => 'Rest'])->assertSessionHasNoErrors();

        $this->assertSame(100.0, $order->fresh()->refundedAmount());
        $this->assertSame(0.0, $order->fresh()->refundableAmount());
        $this->actingAs($u)->post("/orders/{$order->order_no}/refund", ['amount' => '1', 'reason' => 'x'])->assertSessionHasErrors('amount');
        $this->actingAs($u)->get("/orders/{$order->order_no}")->assertOk()->assertSee('Damaged');
    }

    public function test_cannot_refund_unpaid_or_other_merchants_order(): void
    {
        $a = $this->merchant();
        $b = $this->merchant();
        $pending = app(OrderService::class)->create($a, ['amount' => 50, 'client_order_id' => 'P1']);
        $this->actingAs($a)->post("/orders/{$pending->order_no}/refund", ['amount' => '5', 'reason' => 'x'])->assertSessionHasErrors('amount');
        $paid = $this->paidOrder($a, 80, 'P2');
        $this->actingAs($b)->post("/orders/{$paid->order_no}/refund", ['amount' => '5', 'reason' => 'x'])->assertNotFound();
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_ip_burst_flags_high_risk_and_late_payment_flags_low(): void
    {
        $u = $this->merchant();
        $svc = app(OrderService::class);
        for ($i = 1; $i <= 6; $i++) {
            $o = $svc->create($u, ['amount' => 10, 'client_order_id' => "B$i", 'created_ip' => '9.9.9.9']);
        }
        $this->assertSame('high', $o->fresh()->risk_level);
        $this->assertContains('ip_burst', $o->fresh()->risk_flags);

        $late = $svc->create($u, ['amount' => 10, 'client_order_id' => 'L1']);
        Order::whereKey($late->id)->update(['status' => 'expired', 'expires_at' => now()->subMinutes(5)]);
        $late = $svc->markPaid($late->fresh(), 'UTR555555555', null, [], 'manual');
        $this->assertContains('late_payment', $late->fresh()->risk_flags);
        $this->assertSame('low', $late->fresh()->risk_level);

        $clean = $this->paidOrder($u, 20, 'CL');
        $this->assertNull($clean->fresh()->risk_level);
    }

    public function test_failing_account_is_skipped_for_new_orders_then_recovers(): void
    {
        $u = $this->merchant();
        $second = $u->paymentAccounts()->create(['provider' => 'manual', 'label' => 'Backup', 'upi_id' => 'b@upi', 'payee_name' => 'B', 'is_active' => true]);
        $u->update(['rotation_mode' => 'default']);
        $main = $u->paymentAccounts()->where('label', 'Main')->first();

        for ($i = 0; $i < PaymentAccount::FAILURE_LIMIT; $i++) {
            $main->recordCheckFailure();
        }
        $this->assertFalse($main->fresh()->isHealthy());
        $order = app(OrderService::class)->create($u, ['amount' => 10, 'client_order_id' => 'F1']);
        $this->assertSame($second->id, $order->payment_account_id);

        $main->recordCheckSuccess();
        $this->assertTrue($main->fresh()->isHealthy());
        $order = app(OrderService::class)->create($u->fresh(), ['amount' => 10, 'client_order_id' => 'F2']);
        $this->assertSame($main->id, $order->payment_account_id);
    }

    public function test_when_every_account_is_unhealthy_orders_still_go_through(): void
    {
        $u = $this->merchant();
        $main = $u->paymentAccounts()->first();
        for ($i = 0; $i < PaymentAccount::FAILURE_LIMIT; $i++) {
            $main->recordCheckFailure();
        }
        $order = app(OrderService::class)->create($u, ['amount' => 10, 'client_order_id' => 'ALL']);
        $this->assertSame($main->id, $order->payment_account_id);
    }

    public function test_resend_webhook_ignores_previous_success_but_only_for_owner(): void
    {
        Bus::fake();
        $a = $this->merchant();
        $b = $this->merchant();
        $order = $this->paidOrder($a, 40, 'W1');
        $log = $order->webhookLogs()->create(['event' => 'order.paid', 'url' => 'https://x.test/h', 'payload' => [], 'response_code' => 200, 'success' => true, 'attempt' => 1]);

        $this->actingAs($b)->post("/webhooks/{$log->id}/resend")->assertNotFound();
        Bus::assertNotDispatched(SendWebhook::class, fn ($j) => $j->force);
        $this->actingAs($a)->post("/webhooks/{$log->id}/resend")->assertSessionHasNoErrors();
        Bus::assertDispatched(SendWebhook::class, fn ($j) => $j->force && $j->event === 'order.paid');
        $this->actingAs($a)->get('/webhooks')->assertOk()->assertSee('W1');
    }
}
