<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\PlanPayment;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Verifiers\FamPayEmailVerifier;
use App\Services\Verifiers\VerificationResult;
use App\Support\SubscriptionPlans;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlanPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private PaymentAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'business_name' => 'Platform Co']);
        $this->account = $this->admin->paymentAccounts()->create([
            'provider' => 'manual', 'label' => 'Main', 'upi_id' => 'platform@upi',
            'payee_name' => 'Platform', 'is_active' => true, 'is_default' => true,
        ]);
    }

    private function due(string $plan = 'monthly', array $over = []): User
    {
        return User::factory()->create($over + [
            'subscription_status' => User::SUBSCRIPTION_PAYMENT_DUE,
            'subscription_requested_plan' => $plan,
            'subscription_plan' => null,
            'subscription_expires_at' => null,
            'api_key' => null,
        ]);
    }

    private function startPayment(User $merchant): Order
    {
        $this->flushSession(); // switching users in one test session would otherwise trip AuthenticateSession
        $response = $this->actingAs($merchant)->post(route('subscription.pay'));
        $order = PlanPayment::where('user_id', $merchant->id)->latest('id')->firstOrFail()->order;
        $response->assertRedirect(route('pay.show', $order));

        return $order;
    }

    private function markPaid(Order $order, string $utr = '412345678901'): Order
    {
        return app(OrderService::class)->markPaid($order, $utr, null, [], 'manual');
    }

    public function test_each_plan_is_collected_on_the_admins_account_at_the_server_price(): void
    {
        foreach (['monthly' => 199, 'six_months' => 599, 'annual' => 2100] as $plan => $price) {
            $merchant = $this->due($plan);
            $this->actingAs($merchant)->get(route('subscription.show'))->assertOk()->assertSee('Pay ₹'.number_format($price).' now')->assertSee('Platform Co');
            $this->post(route('subscription.pay'), ['amount' => 1, 'plan' => 'monthly']);
            $this->flushSession();

            $payment = PlanPayment::where('user_id', $merchant->id)->sole();
            $this->assertSame($plan, $payment->plan);
            $this->assertEquals($price, $payment->order->amount);
            $this->assertSame($this->admin->id, $payment->order->user_id);
            $this->assertSame($this->account->id, $payment->order->payment_account_id);
            $this->assertSame(Order::PENDING, $payment->order->status);
        }
    }

    public function test_starting_a_payment_does_not_start_the_plan(): void
    {
        $merchant = $this->due();
        $order = $this->startPayment($merchant);

        $this->get(route('pay.show', $order))->assertOk()->assertSee('platform@upi');
        $this->assertSame(User::SUBSCRIPTION_PAYMENT_DUE, $merchant->refresh()->subscription_status);
        $this->get(route('dashboard'))->assertRedirect(route('subscription.show'));
    }

    public function test_verified_payment_starts_the_plan_once(): void
    {
        $this->travelTo(now()->startOfSecond());
        $merchant = $this->due('six_months');
        $order = $this->startPayment($merchant);

        $this->markPaid($order);

        $merchant->refresh();
        $this->assertSame(User::SUBSCRIPTION_ACTIVE, $merchant->subscription_status);
        $this->assertSame('six_months', $merchant->subscription_plan);
        $this->assertTrue($merchant->subscription_expires_at->equalTo(now()->addMonthsNoOverflow(6)));
        $this->assertNotNull($merchant->api_key);
        $this->assertNotNull(PlanPayment::sole()->applied_at);
        $this->assertDatabaseHas('admin_audit_logs', ['subject_id' => $merchant->id, 'action' => 'subscription_paid_online']);

        // A repeated confirmation changes nothing.
        $ends = $merchant->subscription_expires_at;
        $this->travel(3)->days();
        $this->markPaid($order->refresh(), '999999999999');
        $this->assertTrue($merchant->refresh()->subscription_expires_at->equalTo($ends));
        $this->assertDatabaseCount('admin_audit_logs', 1);

        $this->get(route('pay.show', $order))->assertOk()->assertSee('Go to my plan');
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_customer_utr_alone_never_starts_the_plan_but_admin_approval_does(): void
    {
        $merchant = $this->due();
        $order = $this->startPayment($merchant);

        $this->post(route('pay.utr', $order), ['utr' => '412345678901'])->assertRedirect();
        $this->assertSame(Order::REVIEW, $order->refresh()->status);
        $this->assertSame(User::SUBSCRIPTION_PAYMENT_DUE, $merchant->refresh()->subscription_status);
        $this->get(route('subscription.show'))->assertSee('Your payment is being verified')->assertDontSee('now</button>', false);

        // Pressing pay again goes back to the order under review instead of creating another.
        $this->post(route('subscription.pay'))->assertRedirect(route('pay.show', $order));
        $this->assertDatabaseCount('orders', 1);

        $this->flushSession();
        $this->actingAs($this->admin)->get(route('orders.show', $order))->assertOk();
        $this->post(route('orders.approve-utr', $order), ['bank_utr' => '412345678901'])->assertSessionHasNoErrors();

        $this->assertSame(Order::PAID, $order->refresh()->status);
        $this->assertSame(User::SUBSCRIPTION_ACTIVE, $merchant->refresh()->subscription_status);
    }

    public function test_merchant_cannot_confirm_or_approve_their_own_plan_payment(): void
    {
        $merchant = $this->due('monthly', ['subscription_status' => User::SUBSCRIPTION_ACTIVE, 'subscription_plan' => 'monthly', 'subscription_expires_at' => now()->addDay()]);
        $victim = $this->due();
        $order = $this->startPayment($victim);

        $this->flushSession();
        $this->actingAs($merchant)->post(route('orders.mark-paid', $order), ['utr' => '412345678901'])->assertNotFound();
        $this->assertSame(Order::PENDING, $order->refresh()->status);
    }

    public function test_open_checkout_is_reused_and_an_expired_one_is_replaced(): void
    {
        $merchant = $this->due();
        $first = $this->startPayment($merchant);
        $this->assertSame($first->id, $this->startPayment($merchant)->id);
        $this->get(route('subscription.show'))->assertSee('Continue payment');

        $this->travel(1)->hours();
        $second = $this->startPayment($merchant);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(Order::EXPIRED, $first->refresh()->status);
    }

    public function test_late_confirmation_of_an_expired_checkout_still_starts_the_plan(): void
    {
        $merchant = $this->due('annual');
        $first = $this->startPayment($merchant);
        $this->travel(1)->hours();
        $this->startPayment($merchant);

        $this->markPaid($first->refresh());

        $this->assertSame('annual', $merchant->refresh()->subscription_plan);
        $this->assertSame(User::SUBSCRIPTION_ACTIVE, $merchant->subscription_status);
    }

    public function test_second_payment_for_an_active_plan_is_flagged_for_the_admin(): void
    {
        $merchant = $this->due();
        $order = $this->startPayment($merchant);

        $this->withoutMiddleware(RequirePassword::class);
        $this->flushSession();
        $this->actingAs($this->admin)->post(route('admin.merchants.activate', $merchant))->assertSessionHasNoErrors();
        $ends = $merchant->refresh()->subscription_expires_at;

        $this->travel(2)->minutes();
        $this->markPaid($order);

        $this->assertTrue($merchant->refresh()->subscription_expires_at->equalTo($ends));
        $this->assertNull(PlanPayment::sole()->applied_at);
        $this->assertDatabaseHas('admin_audit_logs', ['subject_id' => $merchant->id, 'action' => 'subscription_payment_needs_review']);
        $this->get(route('admin.merchants'))->assertOk()->assertSee('online, plan not changed');
    }

    public function test_payment_does_not_reactivate_a_rejected_account(): void
    {
        $merchant = $this->due();
        $order = $this->startPayment($merchant);
        $merchant->forceFill(['subscription_status' => User::SUBSCRIPTION_REJECTED])->save();

        $this->markPaid($order);

        $this->assertSame(User::SUBSCRIPTION_REJECTED, $merchant->refresh()->subscription_status);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'subscription_payment_needs_review']);
    }

    public function test_admins_own_orders_cannot_fake_a_plan_payment_through_their_reference_fields(): void
    {
        $merchant = $this->due();
        $order = app(OrderService::class)->create($this->admin, ['amount' => 199, 'client_order_id' => 'PLAN-'.$merchant->id.'-FAKE', 'note' => '1 month plan', 'customer_email' => $merchant->email]);

        $this->markPaid($order);

        $this->assertSame(User::SUBSCRIPTION_PAYMENT_DUE, $merchant->refresh()->subscription_status);
    }

    public function test_only_an_approved_plan_can_be_paid_online(): void
    {
        foreach ([User::SUBSCRIPTION_PENDING, User::SUBSCRIPTION_REJECTED] as $status) {
            $this->flushSession();
            $this->actingAs($this->due('monthly', ['subscription_status' => $status]))
                ->post(route('subscription.pay'))->assertRedirect(route('subscription.show'))->assertSessionHasErrors('plan');
        }

        $this->flushSession();
        $this->actingAs($this->admin)->post(route('subscription.pay'))->assertNotFound();
        $this->assertDatabaseCount('orders', 0);
    }

    private function expired(string $plan = 'monthly'): User
    {
        return $this->due($plan, [
            'subscription_status' => User::SUBSCRIPTION_ACTIVE,
            'subscription_plan' => $plan,
            'subscription_started_at' => now()->subMonths(13),
            'subscription_expires_at' => now()->subMinute(),
        ]);
    }

    public function test_each_time_a_plan_ends_the_pay_screen_appears_and_paying_renews_it(): void
    {
        $this->travelTo(now()->startOfSecond());
        $merchant = $this->due('monthly');
        $this->markPaid($this->startPayment($merchant), '100000000000');

        foreach (range(1, 4) as $month) {
            $this->travelTo($merchant->refresh()->subscription_expires_at->copy()->subSecond());
            $this->flushSession();
            $this->actingAs($merchant)->get(route('dashboard'))->assertOk();

            $this->travel(1)->seconds();
            $this->get(route('dashboard'))->assertRedirect(route('subscription.show'));
            $this->get(route('subscription.show'))->assertOk()
                ->assertSee('Subscription expired')->assertSee('Renew online by UPI')->assertSee('Pay ₹199 now');
            $this->assertSame(User::SUBSCRIPTION_EXPIRED, $merchant->refresh()->subscription_status);

            $order = $this->startPayment($merchant);
            $this->assertEquals(199, $order->amount);
            $this->assertFalse($merchant->refresh()->hasSubscriptionAccess());

            $this->markPaid($order, '10000000000'.$month);

            $this->assertTrue($merchant->refresh()->hasSubscriptionAccess());
            $this->assertTrue($merchant->subscription_expires_at->equalTo(now()->addMonthsNoOverflow(1)));
        }

        $this->assertDatabaseCount('orders', 5);
        $this->assertSame(5, PlanPayment::whereNotNull('applied_at')->count());
    }

    public function test_every_plan_offers_itself_again_when_it_ends(): void
    {
        foreach (['six_months' => '599', 'annual' => '2,100'] as $plan => $price) {
            $this->flushSession();
            $this->actingAs($this->expired($plan))->get(route('subscription.show'))->assertOk()->assertSee("Pay ₹{$price} now");
        }
    }

    public function test_renewing_merchant_can_switch_plan_before_paying(): void
    {
        $merchant = $this->expired('monthly');
        $this->actingAs($merchant)->post(route('subscription.pay'), ['plan' => 'annual']);
        $annual = PlanPayment::latest('id')->first();
        $this->assertSame('annual', $annual->plan);
        $this->assertEquals(2100, $annual->order->amount);
        $this->get(route('subscription.show'))->assertSee('Pay ₹199 now');

        $this->post(route('subscription.pay'), ['plan' => 'six_months']);
        $order = PlanPayment::latest('id')->first()->order;
        $this->assertEquals(599, $order->amount);

        $this->markPaid($order);
        $this->assertSame('six_months', $merchant->refresh()->subscription_plan);
        $this->assertTrue($merchant->hasSubscriptionAccess());
    }

    public function test_renewal_rejects_unknown_and_lifetime_plans(): void
    {
        $merchant = $this->expired();

        foreach (['lifetime', 'free'] as $plan) {
            $this->actingAs($merchant)->post(route('subscription.pay'), ['plan' => $plan])->assertSessionHasErrors('plan');
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_admin_handled_renewal_is_still_possible(): void
    {
        $merchant = $this->expired();
        $this->actingAs($merchant)->get(route('subscription.show'))->assertSee('Paying the admin another way?');
        $this->post(route('subscription.request'), ['plan' => 'annual'])->assertSessionHasNoErrors();
        $this->assertSame(User::SUBSCRIPTION_PAYMENT_DUE, $merchant->refresh()->subscription_status);
        $this->get(route('subscription.show'))->assertSee('Pay ₹2,100 now');
    }

    public function test_an_unfinished_review_from_an_earlier_term_does_not_block_renewal(): void
    {
        $merchant = $this->due('monthly');
        $old = $this->startPayment($merchant);
        $this->post(route('pay.utr', $old), ['utr' => '412345678901']);

        // The admin saw the money and started the plan by hand, leaving the order in review.
        $this->travel(1)->minutes();
        $this->withoutMiddleware(RequirePassword::class);
        $this->flushSession();
        $this->actingAs($this->admin)->post(route('admin.merchants.activate', $merchant));

        $this->travel(2)->months();
        $this->flushSession();
        $this->actingAs($merchant->refresh())->get(route('subscription.show'))->assertSee('Pay ₹199 now')->assertDontSee('Your payment is being verified');
        $new = $this->startPayment($merchant);
        $this->assertNotSame($old->id, $new->id);

        // Approving the old order later must not hand out a second term.
        app(OrderService::class)->approveCustomerUtr($old->refresh(), '412345678901');
        $this->assertFalse($merchant->refresh()->hasSubscriptionAccess());
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'subscription_payment_needs_review']);
    }

    public function test_trial_costs_one_rupee_and_lasts_exactly_two_days(): void
    {
        $this->travelTo(now()->startOfSecond());
        $merchant = $this->due('trial');

        $this->actingAs($merchant)->get(route('subscription.show'))->assertSee('2 day trial')->assertSee('Pay ₹1 now');
        $order = $this->startPayment($merchant);
        $this->assertEquals(1, $order->amount);
        $this->markPaid($order);

        $this->assertTrue($merchant->refresh()->subscription_expires_at->equalTo(now()->addDays(2)));

        $this->travelTo($merchant->subscription_expires_at->copy()->subSecond());
        $this->flushSession();
        $this->actingAs($merchant)->get(route('dashboard'))->assertOk();
        $this->travel(1)->seconds();
        $this->get(route('dashboard'))->assertRedirect(route('subscription.show'));

        // After the trial the monthly plan is offered, and the trial is gone.
        $this->get(route('subscription.show'))->assertSee('Pay ₹199 now')->assertDontSee('2 day trial');
    }

    public function test_trial_cannot_be_bought_again_by_any_route(): void
    {
        $merchant = $this->expired('monthly');

        $this->actingAs($merchant)->post(route('subscription.pay'), ['plan' => 'trial'])->assertSessionHasErrors('plan');
        $this->post(route('subscription.request'), ['plan' => 'trial'])->assertSessionHasErrors('plan');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('monthly', $merchant->refresh()->subscription_requested_plan);

        // Even a payment-due record that somehow asks for it is refused.
        $merchant->forceFill(['subscription_status' => User::SUBSCRIPTION_PAYMENT_DUE, 'subscription_requested_plan' => 'trial'])->save();
        $this->post(route('subscription.pay'))->assertSessionHasErrors('plan');
        $this->withoutMiddleware(RequirePassword::class);
        $this->flushSession();
        $this->actingAs($this->admin)->post(route('admin.merchants.activate', $merchant))->assertStatus(409);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_new_signup_can_choose_the_trial_and_admin_can_approve_it(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('2 day trial');
        $this->get(route('register'))->assertSee('2 day trial');
        $this->post(route('register'), [
            'name' => 'Tri', 'business_name' => 'Trial Shop', 'email' => 'trial@example.test', 'plan' => 'trial',
            'password' => 'StrongPass123!', 'password_confirmation' => 'StrongPass123!',
        ])->assertSessionHasNoErrors();
        $merchant = User::where('email', 'trial@example.test')->sole();

        $this->withoutMiddleware(RequirePassword::class);
        $this->flushSession();
        $this->actingAs($this->admin)->post(route('admin.merchants.approve', $merchant), ['plan' => 'trial'])->assertSessionHasNoErrors();

        $this->assertEquals(1, $this->startPayment($merchant->refresh())->amount);
    }

    public function test_without_an_admin_upi_account_the_manual_route_is_shown(): void
    {
        $this->account->update(['is_active' => false]);
        $merchant = $this->due();

        $this->actingAs($merchant)->get(route('subscription.show'))->assertOk()
            ->assertSee('Manual payment with the admin')->assertDontSee('Pay online by UPI');
        $this->post(route('subscription.pay'))->assertSessionHasErrors('plan');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_plan_orders_send_no_webhooks_to_the_admins_websites(): void
    {
        Http::fake();
        $this->admin->forceFill(['webhook_url' => 'https://example.com/hook', 'api_secret' => 'secret'])->save();
        $order = $this->startPayment($this->due());

        $this->markPaid($order);

        Http::assertNothingSent();
        $this->assertDatabaseCount('webhook_logs', 0);
        $this->assertSame(Order::PAID, $order->refresh()->status);
    }

    public function test_provider_verification_starts_the_plan(): void
    {
        Queue::fake();
        $this->account->update(['provider' => 'fampay_email', 'credentials' => ['imap_username' => 'a@gmail.com', 'imap_password' => 'app-pass']]);
        $merchant = $this->due();
        $order = $this->startPayment($merchant);

        $this->mock(FamPayEmailVerifier::class)->shouldReceive('check')->once()
            ->andReturn(VerificationResult::paid(utr: '412345678901', txnId: 'mail-1', raw: []));

        $this->getJson(route('pay.status', $order))->assertOk()->assertJson(['status' => Order::PAID]);

        $this->assertSame(User::SUBSCRIPTION_ACTIVE, $merchant->refresh()->subscription_status);
        $this->assertSame(SubscriptionPlans::amount('monthly'), 199);
    }
}
