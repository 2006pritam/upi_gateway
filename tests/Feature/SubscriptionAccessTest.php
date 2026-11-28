<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SubscriptionPlans;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionAccessTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'email' => 'modakpritam06@gmail.com']);
    }

    private function merchant(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Pritam Merchant',
            'business_name' => 'Pritam Store',
            'email' => 'merchant@example.test',
            'subscription_status' => User::SUBSCRIPTION_ACTIVE,
        ], $attributes));
    }

    private function apiHeaders(User $user): array
    {
        return [
            'X-Api-Key' => $user->api_key,
            'X-Api-Secret' => $user->api_secret,
            'Accept' => 'application/json',
        ];
    }

    public function test_signup_creates_request_and_does_not_grant_app_or_api_access(): void
    {
        $response = $this->post(route('register'), [
            'name' => 'Pritam',
            'business_name' => 'Pritam Store',
            'email' => 'newmerchant@example.test',
            'phone' => '9876543210',
            'plan' => 'monthly',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ]);

        $response->assertRedirect(route('subscription.show'));
        $user = User::where('email', 'newmerchant@example.test')->firstOrFail();
        $this->assertSame(User::SUBSCRIPTION_PENDING, $user->subscription_status);
        $this->assertSame('monthly', $user->subscription_requested_plan);
        $this->assertNull($user->api_key);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('subscription.show'));
        $this->get(route('password.confirm'))->assertRedirect(route('subscription.show'));
        $this->get(route('subscription.show'))->assertOk()->assertSee('waiting for admin approval');
    }

    public function test_new_model_users_default_to_pending_approval(): void
    {
        $user = User::create([
            'name' => 'Unreviewed Merchant',
            'email' => 'unreviewed@example.test',
            'password' => 'StrongPass123!',
        ]);

        $this->assertSame(User::SUBSCRIPTION_PENDING, $user->subscription_status);
        $this->assertFalse($user->hasSubscriptionAccess());
    }

    public function test_admin_approval_only_requests_payment_and_admin_confirmation_starts_selected_term(): void
    {
        $admin = $this->admin();
        $merchant = $this->merchant([
            'subscription_status' => User::SUBSCRIPTION_PENDING,
            'subscription_requested_plan' => 'six_months',
        ]);

        $this->withoutMiddleware(RequirePassword::class)->actingAs($admin)
            ->post(route('admin.merchants.approve', $merchant), ['plan' => 'six_months'])
            ->assertRedirect();

        $merchant->refresh();
        $this->assertSame(User::SUBSCRIPTION_PAYMENT_DUE, $merchant->subscription_status);
        $this->assertNull($merchant->subscription_started_at);
        $this->assertFalse($merchant->hasSubscriptionAccess());

        $this->actingAs($merchant)->post(route('subscription.request'), ['plan' => 'annual'])
            ->assertSessionHasErrors('plan');
        $merchant->refresh();
        $this->assertSame('six_months', $merchant->subscription_requested_plan);

        $startedAt = now();
        $this->actingAs($admin)->post(route('admin.merchants.activate', $merchant), [
            'payment_reference' => 'UPI-REF-123',
        ])->assertRedirect();

        $merchant->refresh();
        $this->assertSame(User::SUBSCRIPTION_ACTIVE, $merchant->subscription_status);
        $this->assertSame('six_months', $merchant->subscription_plan);
        $this->assertSame(SubscriptionPlans::expiry('six_months', $startedAt)->timestamp, $merchant->subscription_expires_at->timestamp);
        $this->assertNotNull($merchant->subscription_paid_at);
        $this->assertNotNull($merchant->api_key);
        $this->assertTrue($merchant->hasSubscriptionAccess());
        $this->assertDatabaseHas('admin_audit_logs', [
            'subject_type' => 'user',
            'subject_id' => $merchant->id,
            'action' => 'subscription_payment_confirmed',
        ]);
    }

    public function test_payment_due_merchant_cannot_use_merchant_api(): void
    {
        $merchant = $this->merchant(['subscription_status' => User::SUBSCRIPTION_PAYMENT_DUE]);
        $merchant->regenerateApiCredentials();

        $this->getJson('/api/v1/accounts', $this->apiHeaders($merchant))
            ->assertForbidden()
            ->assertJsonPath('subscription_status', User::SUBSCRIPTION_PAYMENT_DUE);
    }

    public function test_expired_subscription_redirects_web_access_and_blocks_api_until_renewed(): void
    {
        $merchant = $this->merchant([
            'subscription_status' => User::SUBSCRIPTION_ACTIVE,
            'subscription_plan' => 'monthly',
            'subscription_expires_at' => now()->subSecond(),
        ]);
        $merchant->regenerateApiCredentials();

        $this->actingAs($merchant)->get(route('orders.index'))->assertRedirect(route('subscription.show'));
        $this->assertSame(User::SUBSCRIPTION_EXPIRED, $merchant->fresh()->subscription_status);
        $this->getJson('/api/v1/accounts', $this->apiHeaders($merchant))->assertForbidden()
            ->assertJsonPath('subscription_status', User::SUBSCRIPTION_EXPIRED);

        $this->post(route('subscription.request'), ['plan' => 'annual'])->assertRedirect(route('subscription.show'));
        $merchant->refresh();
        $this->assertSame(User::SUBSCRIPTION_PAYMENT_DUE, $merchant->subscription_status);
        $this->assertSame('annual', $merchant->subscription_requested_plan);
        $this->get(route('dashboard'))->assertRedirect(route('subscription.show'));
    }

    public function test_admin_cannot_activate_unapproved_request_or_choose_unknown_plan(): void
    {
        $admin = $this->admin();
        $merchant = $this->merchant([
            'subscription_status' => User::SUBSCRIPTION_PENDING,
            'subscription_requested_plan' => 'monthly',
        ]);

        $this->withoutMiddleware(RequirePassword::class)->actingAs($admin)
            ->post(route('admin.merchants.activate', $merchant))
            ->assertStatus(409);

        $this->actingAs($admin)->post(route('admin.merchants.approve', $merchant), ['plan' => 'free_forever'])
            ->assertSessionHasErrors('plan');
        $this->assertSame(User::SUBSCRIPTION_PENDING, $merchant->fresh()->subscription_status);
    }
}
