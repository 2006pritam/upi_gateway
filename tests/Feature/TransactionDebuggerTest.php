<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\User;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionDebuggerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $merchant;

    private PaymentAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequirePassword::class);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->merchant = User::factory()->create(['name' => 'Acme Merchant', 'email' => 'merchant@example.test']);
        $this->account = $this->merchant->paymentAccounts()->create([
            'provider' => 'manual', 'label' => 'Main UPI', 'upi_id' => 'merchant@upi',
            'payee_name' => 'Acme', 'is_active' => true,
        ]);
    }

    public function test_only_admins_can_access_the_debugger(): void
    {
        $order = $this->order();

        $this->get(route('admin.transactions.index'))->assertRedirect(route('login'));
        $this->actingAs($this->merchant)->get(route('admin.transactions.index'))->assertForbidden();
        $this->actingAs($this->merchant)->get(route('admin.transactions.show', $order))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.transactions.index'))->assertOk();
    }

    public function test_search_finds_stored_transaction_and_merchant_identifiers(): void
    {
        $target = $this->order(['order_no' => 'ORDSEARCHTARGET', 'client_order_id' => 'CLIENT-LOOKUP', 'utr' => '998877665544', 'provider_txn_id' => 'PROVIDER-TXN-7']);
        $this->order(['order_no' => 'ORDOTHERRECORD']);

        foreach ([(string) $target->id, 'ORDSEARCH', 'CLIENT-LOOKUP', '998877665544', 'PROVIDER-TXN-7', 'Acme Merchant'] as $search) {
            $orders = $this->actingAs($this->admin)->get(route('admin.transactions.index', ['q' => $search]))
                ->assertOk()->viewData('orders');
            $this->assertTrue($orders->getCollection()->contains('id', $target->id), "Search failed for {$search}");
        }
    }

    public function test_status_provider_environment_and_date_filters_are_applied(): void
    {
        $live = $this->order(['status' => Order::PAID, 'created_at' => '2026-11-01 10:00:00']);
        $sandbox = $this->order(['order_no' => 'TSTFILTERTARGET', 'environment' => Order::SANDBOX, 'payment_account_id' => null, 'status' => Order::FAILED, 'created_at' => '2026-11-05 10:00:00']);

        $liveResults = $this->results(['status' => 'paid', 'provider' => 'manual', 'environment' => 'live', 'date_from' => '2026-11-01', 'date_to' => '2026-11-01']);
        $this->assertSame([$live->id], $liveResults->pluck('id')->all());

        $sandboxResults = $this->results(['provider' => 'sandbox', 'environment' => 'sandbox']);
        $this->assertSame([$sandbox->id], $sandboxResults->pluck('id')->all());
    }

    public function test_details_mask_customer_data_and_never_expose_provider_payload_secrets(): void
    {
        $order = $this->order([
            'customer_name' => 'Jane Customer', 'customer_email' => 'jane.customer@example.test',
            'customer_mobile' => '9876543210',
            'provider_response' => ['message' => '<b>Declined</b> token=super-secret 9876543210 jane.customer@example.test', 'access_token' => 'never-show-me'],
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.transactions.show', $order))->assertOk();
        $response->assertSee('J************')->assertSee('j************@example.test')->assertSee('******3210')
            ->assertSee('Declined')->assertSee('[redacted credential]')->assertSee('[redacted number]')->assertSee('[redacted email]')
            ->assertDontSee('Jane Customer')->assertDontSee('jane.customer@example.test')->assertDontSee('9876543210')
            ->assertDontSee('super-secret')->assertDontSee('never-show-me');
    }

    public function test_debugger_is_read_only_and_does_not_call_payment_handlers(): void
    {
        $order = $this->order(['status' => Order::PENDING]);
        $before = $order->fresh()->getAttributes();

        $this->actingAs($this->admin)->get(route('admin.transactions.show', $order))->assertOk();
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->actingAs($this->admin)->post('/admin/transaction-debugger/'.$order->order_no)->assertStatus(405);
        $this->assertSame(Order::PENDING, $order->fresh()->status);
    }

    public function test_missing_history_is_reported_without_fabricated_events(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)->get(route('admin.transactions.show', $order))->assertOk()
            ->assertSee('No webhook attempts recorded')
            ->assertSee('Order created')
            ->assertSee('Current status: PENDING')
            ->assertDontSee('Provider response received')
            ->assertDontSee('Checkout initiated');
    }

    public function test_results_are_paginated_and_invalid_filters_are_rejected(): void
    {
        foreach (range(1, 26) as $index) {
            $this->order(['order_no' => 'ORDPAGE'.str_pad((string) $index, 4, '0', STR_PAD_LEFT)]);
        }

        $paginator = $this->actingAs($this->admin)->get(route('admin.transactions.index'))->assertOk()->viewData('orders');
        $this->assertSame(25, $paginator->perPage());
        $this->assertSame(26, $paginator->total());

        $this->from(route('admin.transactions.index'))->actingAs($this->admin)
            ->get(route('admin.transactions.index', ['q' => '<script>', 'status' => 'refunded', 'date_from' => 'not-a-date']))
            ->assertRedirect(route('admin.transactions.index'))
            ->assertSessionHasErrors(['q', 'status', 'date_from']);
    }

    private function results(array $filters)
    {
        return $this->actingAs($this->admin)->get(route('admin.transactions.index', $filters))
            ->assertOk()->viewData('orders')->getCollection();
    }

    private function order(array $attributes = []): Order
    {
        $order = new Order(array_merge([
            'order_no' => 'ORD'.strtoupper(fake()->unique()->bothify('????????????????')),
            'payment_account_id' => $this->account->id,
            'client_order_id' => fake()->unique()->bothify('CLIENT-####????'),
            'amount' => '125.00', 'status' => Order::PENDING,
            'expires_at' => now()->addMinutes(15),
        ], $attributes));
        $order->forceFill($attributes + ['environment' => $attributes['environment'] ?? Order::LIVE]);
        $order->user()->associate($this->merchant);
        $order->save();

        return $order;
    }
}
