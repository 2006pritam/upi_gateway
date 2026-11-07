<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Verifiers\PaytmBusinessVerifier;
use App\Services\Verifiers\PaytmPgVerifier;
use App\Services\Verifiers\PhonePePgVerifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Transaction integrity and payment security tests.
 *
 * Covers:
 *  - Terminal state protection (PAID order cannot be re-paid)
 *  - Provider callback blocked on REVIEW orders
 *  - Race condition protection on approve/reject UTR
 *  - Race condition protection on concurrent UTR submissions
 *  - Order ID mismatch rejection for all three providers
 *  - Webhook idempotency (no double-delivery on retry)
 */
class TransactionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function merchant(): User
    {
        $user = User::create([
            'name' => 'Ravi',
            'business_name' => 'Ravi Store',
            'email' => 'ravi@example.com',
            'password' => 'password123',
        ]);
        $user->regenerateApiCredentials();
        $user->paymentAccounts()->create([
            'provider' => 'paytm_business',
            'label' => 'Main',
            'upi_id' => 'paytmqr123@paytm',
            'payee_name' => 'Ravi Store',
            'credentials' => ['mid' => 'TESTMID123'],
            'is_default' => true,
        ]);

        return $user;
    }

    private function manualMerchant(): User
    {
        $user = User::create([
            'name' => 'Manual',
            'email' => 'manual@example.com',
            'password' => 'password123',
        ]);
        $user->paymentAccounts()->create([
            'provider' => 'manual',
            'label' => 'GPay',
            'upi_id' => 'manual@okaxis',
            'payee_name' => 'Manual',
            'is_default' => true,
        ]);

        return $user;
    }

    private function order(User $merchant, array $extra = []): Order
    {
        return app(OrderService::class)->create($merchant, array_merge(
            ['amount' => 100, 'client_order_id' => 'T-'.uniqid()],
            $extra
        ));
    }

    // ── 1. Terminal state: PAID order cannot be re-paid ───────────────────────

    public function test_paid_order_cannot_be_marked_paid_again(): void
    {
        Queue::fake();
        $merchant = $this->merchant();
        $order = $this->order($merchant);

        $service = app(OrderService::class);
        $service->markPaid($order, '111111111111', 'TXN1', [], 'provider');
        $this->assertSame(Order::PAID, $order->fresh()->status);

        // Second call must be a no-op (returns false internally, no second webhook).
        $service->markPaid($order, '222222222222', 'TXN2', [], 'provider');
        $this->assertSame('111111111111', $order->fresh()->utr);
        Queue::assertPushed(SendWebhook::class, 1); // only one webhook
    }

    public function test_database_rejects_duplicate_client_order_id_for_one_merchant(): void
    {
        $merchant = $this->merchant();
        $this->order($merchant, ['client_order_id' => 'same-id']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->order($merchant, ['client_order_id' => 'same-id']);
    }

    public function test_approval_rolls_back_with_its_surrounding_transaction(): void
    {
        Queue::fake();
        $merchant = $this->manualMerchant();
        $order = $this->order($merchant);
        app(OrderService::class)->submitCustomerUtr($order, '412345678901');

        try {
            DB::transaction(function () use ($order) {
                app(OrderService::class)->approveCustomerUtr($order, '412345678901');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }

        $this->assertSame(Order::REVIEW, $order->fresh()->status);
    }

    // ── 2. Provider callback must not re-open a REVIEW order ─────────────────

    public function test_provider_callback_does_not_override_review_status(): void
    {
        Queue::fake();
        $merchant = $this->manualMerchant();
        $order = $this->order($merchant);

        // Customer submits UTR → order goes to REVIEW.
        $this->post(route('pay.utr', $order), ['utr' => '412345678901']);
        $this->assertSame(Order::REVIEW, $order->fresh()->status);

        // A provider-sourced markPaid (e.g. from a late Paytm callback) must be blocked.
        $service = app(OrderService::class);
        $service->markPaid($order->fresh(), '412345678901', 'TXN1', [], 'provider');

        // Order must remain in REVIEW, not jump to PAID via the provider path.
        $this->assertSame(Order::REVIEW, $order->fresh()->status);
        Queue::assertNotPushed(SendWebhook::class, fn ($j) => $j->event === 'order.paid');
    }

    // ── 3. Approve UTR: concurrent approve + reject is safe ──────────────────

    public function test_approve_utr_checks_status_inside_lock(): void
    {
        Queue::fake();
        $merchant = $this->manualMerchant();
        $order = $this->order($merchant);

        $this->post(route('pay.utr', $order), ['utr' => '412345678901']);

        // Simulate: reject happens first (changes status to PENDING).
        $service = app(OrderService::class);
        $service->rejectCustomerUtr($order->fresh(), 'Not found');
        $this->assertSame(Order::PENDING, $order->fresh()->status);

        // Now approve arrives late — must fail because status is no longer REVIEW.
        $this->actingAs($merchant)
            ->post(route('orders.approve-utr', $order), ['bank_utr' => '412345678901'])
            ->assertSessionHasErrors('bank_utr');

        $this->assertSame(Order::PENDING, $order->fresh()->status);
    }

    public function test_reject_utr_checks_status_inside_lock(): void
    {
        Queue::fake();
        $merchant = $this->manualMerchant();
        $order = $this->order($merchant);

        $this->post(route('pay.utr', $order), ['utr' => '412345678901']);

        // Simulate: approve happens first.
        $service = app(OrderService::class);
        $service->approveCustomerUtr($order->fresh(), '412345678901');
        $this->assertSame(Order::PAID, $order->fresh()->status);

        // Reject arrives late — must fail because status is no longer REVIEW.
        $this->actingAs($merchant)
            ->post(route('orders.reject-utr', $order), ['reason' => 'late reject'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(Order::PAID, $order->fresh()->status);
    }

    // ── 4. Duplicate UTR submission is blocked atomically ────────────────────

    public function test_same_utr_on_two_concurrent_submissions_is_blocked(): void
    {
        Queue::fake();
        $merchant = $this->manualMerchant();
        $order1 = $this->order($merchant);
        $order2 = $this->order($merchant);

        // First submission succeeds.
        $this->post(route('pay.utr', $order1), ['utr' => '412345678901']);
        $this->assertSame(Order::REVIEW, $order1->fresh()->status);

        // Second submission with the same UTR must be rejected.
        $this->post(route('pay.utr', $order2), ['utr' => '412345678901'])
            ->assertSessionHasErrors('utr');
        $this->assertSame(Order::PENDING, $order2->fresh()->status);
    }

    // ── 5. Paytm Business: order ID mismatch is rejected ─────────────────────

    public function test_paytm_business_rejects_response_with_wrong_order_id(): void
    {
        Queue::fake();
        $merchant = $this->merchant();
        $order = $this->order($merchant);

        Http::fake(['securegw.paytm.in/*' => Http::response([
            'STATUS' => 'TXN_SUCCESS',
            'TXNAMOUNT' => '100.00',
            'BANKTXNID' => '111111111111',
            'TXNID' => 'T1',
            'ORDERID' => 'DIFFERENT_ORDER_NO', // wrong order
        ])]);

        $result = app(PaytmBusinessVerifier::class)->check($order->load('paymentAccount'));

        $this->assertFalse($result->paid);
        $this->assertSame(Order::PENDING, $order->fresh()->status);
    }

    public function test_paytm_business_accepts_response_with_correct_order_id(): void
    {
        Queue::fake();
        $merchant = $this->merchant();
        $order = $this->order($merchant);

        Http::fake(['securegw.paytm.in/*' => Http::response([
            'STATUS' => 'TXN_SUCCESS',
            'TXNAMOUNT' => '100.00',
            'BANKTXNID' => '111111111111',
            'TXNID' => 'T1',
            'ORDERID' => $order->order_no,
        ])]);

        $result = app(PaytmBusinessVerifier::class)->check($order->load('paymentAccount'));

        $this->assertTrue($result->paid);
        $this->assertSame('111111111111', $result->utr);
    }

    // ── 6. Paytm PG: order ID mismatch is rejected ───────────────────────────

    public function test_paytm_pg_rejects_response_with_wrong_order_id(): void
    {
        Queue::fake();
        $merchant = User::create(['name' => 'PG', 'email' => 'pg@example.com', 'password' => 'password123']);
        $merchant->regenerateApiCredentials();
        $merchant->paymentAccounts()->create([
            'provider' => 'paytm_pg',
            'label' => 'Paytm PG',
            'payee_name' => 'PG Store',
            'credentials' => [
                'mid' => 'PGMID',
                'merchant_key' => 'abcd1234EFGH5678',
                'website_name' => 'WEBSTAGING',
                'environment' => 'sandbox',
            ],
            'is_default' => true,
        ]);
        $order = $this->order($merchant);

        Http::fake(['securestage.paytmpayments.com/v3/order/status' => Http::response([
            'body' => [
                'resultInfo' => ['resultStatus' => 'TXN_SUCCESS'],
                'txnId' => 'PTM1',
                'bankTxnId' => '222222222222',
                'txnAmount' => '100.00',
                'orderId' => 'WRONG_ORDER_NO',
            ],
        ])]);

        $result = app(PaytmPgVerifier::class)->check($order->load('paymentAccount'));

        $this->assertFalse($result->paid);
    }

    // ── 7. PhonePe PG: order ID mismatch is rejected ─────────────────────────

    public function test_phonepe_rejects_response_with_wrong_order_id(): void
    {
        Queue::fake();
        $merchant = User::create(['name' => 'PP', 'email' => 'pp@example.com', 'password' => 'password123']);
        $merchant->regenerateApiCredentials();
        $merchant->paymentAccounts()->create([
            'provider' => 'phonepe_pg',
            'label' => 'PhonePe',
            'payee_name' => 'PP Store',
            'credentials' => [
                'client_id' => 'CLIENT1',
                'client_secret' => 'SECRET1',
                'client_version' => '1',
                'environment' => 'sandbox',
            ],
            'is_default' => true,
        ]);
        $order = $this->order($merchant);

        Http::fake([
            '*/v1/oauth/token' => Http::response(['access_token' => 'TOKEN', 'expires_at' => time() + 3600]),
            "*/checkout/v2/order/{$order->order_no}/status*" => Http::response([
                'orderId' => 'PHONEPE_INTERNAL_ID',
                'merchantOrderId' => 'WRONG_ORDER_NO',
                'state' => 'COMPLETED',
                'amount' => 10000,
            ]),
        ]);

        $result = app(PhonePePgVerifier::class)->check($order->load('paymentAccount'));

        $this->assertFalse($result->paid);
    }

    // ── 8. Webhook idempotency: no double-delivery on queue retry ─────────────

    public function test_webhook_is_not_delivered_twice_on_queue_retry(): void
    {
        Http::fake(['merchant.test/*' => Http::response('ok', 200)]);

        $merchant = $this->merchant();
        $merchant->forceFill(['webhook_url' => 'https://merchant.test/hook'])->save();
        $order = $this->order($merchant);

        // First delivery succeeds.
        (new SendWebhook($order, 'order.paid'))->handle();
        $this->assertSame(1, $order->webhookLogs()->where('success', true)->count());

        // Simulate queue retry (worker crashed after delivery, before job deletion).
        (new SendWebhook($order, 'order.paid'))->handle();

        // Only one HTTP request should have been made total.
        Http::assertSentCount(1);
        $this->assertSame(1, $order->webhookLogs()->where('success', true)->count());
    }

    // ── 9. Expired order cannot be re-expired ────────────────────────────────

    public function test_expired_order_is_not_re_expired(): void
    {
        Queue::fake();
        $merchant = $this->merchant();
        $order = $this->order($merchant);

        $service = app(OrderService::class);
        $service->expire($order);
        $this->assertSame(Order::EXPIRED, $order->fresh()->status);

        // Second expire call must be a no-op.
        $service->expire($order->fresh());
        Queue::assertPushed(SendWebhook::class, 1); // only one expired webhook
    }

    // ── 10. Cross-merchant: merchant B cannot approve merchant A's UTR ────────

    public function test_merchant_b_cannot_approve_merchant_a_utr_review(): void
    {
        Queue::fake();
        $merchantA = $this->manualMerchant();
        $order = $this->order($merchantA);

        $this->post(route('pay.utr', $order), ['utr' => '412345678901']);
        $this->assertSame(Order::REVIEW, $order->fresh()->status);

        $merchantB = User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'password123']);

        $this->actingAs($merchantB)
            ->post(route('orders.approve-utr', $order), ['bank_utr' => '412345678901'])
            ->assertNotFound();

        $this->assertSame(Order::REVIEW, $order->fresh()->status);
    }
}
