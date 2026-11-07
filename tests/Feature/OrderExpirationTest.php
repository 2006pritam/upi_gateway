<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderExpirationTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    private PaymentAccount $account;

    private OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create(['role' => 'merchant']);
        $this->account = new PaymentAccount([
            'provider' => 'manual',
            'label' => 'Manual Account',
            'upi_id' => 'expire@upi',
            'payee_name' => 'Expire Test',
            'daily_limit' => 50000.00,
            'is_active' => true,
            'is_default' => true,
        ]);
        $this->account->user()->associate($this->merchant);
        $this->account->save();

        $this->orderService = app(OrderService::class);
    }

    public function test_order_creation_persists_default_expires_at(): void
    {
        config(['gateway.order_expiry_minutes' => 20]);
        $frozenNow = Carbon::parse('2026-11-07 12:00:00');
        Carbon::setTestNow($frozenNow);

        $order = $this->orderService->create($this->merchant, [
            'amount' => 500.00,
            'client_order_id' => 'EXP-DEFAULT-001',
        ]);

        $this->assertNotNull($order->expires_at);
        $this->assertSame('2026-11-07 12:20:00', $order->expires_at->format('Y-m-d H:i:s'));
        $this->assertFalse($order->isPastExpiry());

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'expires_at' => '2026-11-07 12:20:00',
        ]);
    }

    public function test_direct_order_creation_without_expires_at_uses_configured_duration(): void
    {
        config(['gateway.order_expiry_minutes' => 25]);
        $frozenNow = Carbon::parse('2026-11-07 14:00:00');
        Carbon::setTestNow($frozenNow);

        $order = new Order([
            'order_no' => 'ORD_NO_EXP_001',
            'payment_account_id' => $this->account->id,
            'client_order_id' => 'DIRECT-001',
            'amount' => 150.00,
            'status' => Order::PENDING,
        ]);
        $order->user()->associate($this->merchant);
        $order->save();

        $this->assertNotNull($order->expires_at);
        $this->assertSame('2026-11-07 14:25:00', $order->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_explicit_expires_at_is_preserved_and_not_overwritten(): void
    {
        $customExpiry = Carbon::parse('2026-11-07 18:45:00');

        $order = new Order([
            'order_no' => 'ORD_CUSTOM_EXP_001',
            'payment_account_id' => $this->account->id,
            'client_order_id' => 'CUSTOM-EXP-001',
            'amount' => 200.00,
            'status' => Order::PENDING,
            'expires_at' => $customExpiry,
        ]);
        $order->user()->associate($this->merchant);
        $order->save();

        $this->assertSame('2026-11-07 18:45:00', $order->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_pending_order_expiration_detection(): void
    {
        $frozenNow = Carbon::parse('2026-11-07 10:00:00');
        Carbon::setTestNow($frozenNow);

        $order = $this->orderService->create($this->merchant, [
            'amount' => 100.00,
            'client_order_id' => 'PENDING-EXP-001',
        ]);

        $this->assertFalse($order->isPastExpiry());

        // Fast forward 16 minutes (past default 15-minute expiry)
        Carbon::setTestNow($frozenNow->copy()->addMinutes(16));

        $this->assertTrue($order->isPastExpiry());

        // Calling check() transitions past-due pending order to expired
        $checked = $this->orderService->check($order);
        $this->assertSame(Order::EXPIRED, $checked->status);
    }

    public function test_sandbox_order_expiration(): void
    {
        $frozenNow = Carbon::parse('2026-11-07 11:00:00');
        Carbon::setTestNow($frozenNow);

        $sandboxOrder = $this->orderService->create($this->merchant, [
            'amount' => 50.00,
            'client_order_id' => 'SANDBOX-EXP-001',
        ], Order::SANDBOX);

        $this->assertSame(Order::SANDBOX, $sandboxOrder->environment);
        $this->assertFalse($sandboxOrder->isPastExpiry());

        // Fast forward past expiry
        Carbon::setTestNow($frozenNow->copy()->addMinutes(16));
        $this->assertTrue($sandboxOrder->isPastExpiry());

        $checked = $this->orderService->check($sandboxOrder);
        $this->assertSame(Order::EXPIRED, $checked->status);
    }

    public function test_legacy_null_expiry_is_repaired_before_scheduled_check(): void
    {
        config(['gateway.order_expiry_minutes' => 20]);
        $order = $this->orderService->create($this->merchant, [
            'amount' => 80.00,
            'client_order_id' => 'NULL-EXP-RECOVERY-001',
        ]);
        DB::table('orders')->where('id', $order->id)->update(['expires_at' => null]);

        $checked = $this->orderService->check($order->fresh());

        $this->assertNotNull($checked->expires_at);
        $this->assertSame(Order::PENDING, $checked->status);
    }

    public function test_expiry_migration_rollback_refuses_null_rows_without_changing_data(): void
    {
        $order = $this->orderService->create($this->merchant, [
            'amount' => 81.00,
            'client_order_id' => 'NULL-EXP-ROLLBACK-001',
        ]);
        DB::table('orders')->where('id', $order->id)->update(['expires_at' => null]);

        $migration = require database_path('migrations/2026_11_09_000003_make_orders_expires_at_nullable.php');

        try {
            $migration->down();
            $this->fail('The rollback should refuse NULL expiry rows.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Repair those rows using an approved expiry policy', $exception->getMessage());
        }

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::PENDING,
            'expires_at' => null,
        ]);
    }

    public function test_expiry_migration_rollback_and_remigration_preserve_paid_pending_and_expired_rows(): void
    {
        $pending = $this->orderService->create($this->merchant, ['amount' => 82.00, 'client_order_id' => 'ROLLBACK-PENDING']);
        $paid = $this->orderService->create($this->merchant, ['amount' => 83.00, 'client_order_id' => 'ROLLBACK-PAID']);
        $expired = $this->orderService->create($this->merchant, ['amount' => 84.00, 'client_order_id' => 'ROLLBACK-EXPIRED']);
        $paid->forceFill(['status' => Order::PAID, 'utr' => '111111111111'])->save();
        $expired->forceFill(['status' => Order::EXPIRED])->save();
        $before = DB::table('orders')->whereIn('id', [$pending->id, $paid->id, $expired->id])->get()->keyBy('id');

        $migration = require database_path('migrations/2026_11_09_000003_make_orders_expires_at_nullable.php');
        $migration->down();
        $migration->up();

        $after = DB::table('orders')->whereIn('id', [$pending->id, $paid->id, $expired->id])->get()->keyBy('id');
        foreach ($before as $id => $row) {
            $this->assertSame($row->status, $after[$id]->status);
            $this->assertSame($row->expires_at, $after[$id]->expires_at);
            $this->assertSame($row->amount, $after[$id]->amount);
        }
    }

    public function test_api_representation_contains_iso8601_expires_at(): void
    {
        $frozenNow = Carbon::parse('2026-11-07 09:30:00');
        Carbon::setTestNow($frozenNow);

        $order = $this->orderService->create($this->merchant, [
            'amount' => 300.00,
            'client_order_id' => 'API-EXP-001',
        ]);

        $apiData = $order->toApiArray();
        $this->assertArrayHasKey('expires_at', $apiData);
        $this->assertSame($order->expires_at->toIso8601String(), $apiData['expires_at']);
    }
}
