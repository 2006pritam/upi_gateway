<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAddAccountTest extends TestCase
{
    use RefreshDatabase;

    private function form(array $over = []): array
    {
        return $over + [
            'name' => 'Asha', 'business_name' => 'Asha Store', 'email' => 'asha@example.test',
            'password' => 'StrongPass123!', 'password_confirmation' => 'StrongPass123!',
        ];
    }

    private function actingAsAdmin(): void
    {
        $this->withoutMiddleware(RequirePassword::class)->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_account_added_by_admin_has_lifetime_web_and_api_access(): void
    {
        $this->actingAsAdmin();
        $this->post(route('admin.merchants.store'), $this->form())->assertRedirect(route('admin.merchants'))->assertSessionHasNoErrors();

        $merchant = User::where('email', 'asha@example.test')->sole();
        $this->assertSame('merchant', $merchant->role);
        $this->assertSame('lifetime', $merchant->subscription_plan);
        $this->assertNull($merchant->subscription_expires_at);
        $this->assertNotNull($merchant->api_key);
        $this->assertDatabaseHas('admin_audit_logs', ['subject_id' => $merchant->id, 'action' => 'merchant_created_lifetime']);
        $this->get(route('admin.merchants'))->assertOk()->assertSee('Never expires');

        $this->travel(20)->years();
        $this->actingAs($merchant)->get(route('dashboard'))->assertOk();
        $this->get(route('subscription.show'))->assertOk()->assertSee('Lifetime')->assertSee('Never')->assertDontSee('Manual payment with the admin');
        $this->getJson('/api/v1/accounts', ['X-Api-Key' => $merchant->api_key, 'X-Api-Secret' => $merchant->api_secret])->assertOk();
    }

    public function test_added_account_can_log_in_with_the_password_the_admin_chose(): void
    {
        $this->actingAsAdmin();
        $this->post(route('admin.merchants.store'), $this->form())->assertSessionHasNoErrors();
        auth()->logout();

        $this->post('/login', ['email' => 'asha@example.test', 'password' => 'StrongPass123!'])->assertRedirect();
        $this->assertSame('asha@example.test', auth()->user()?->email);
    }

    public function test_form_cannot_create_an_admin_and_rejects_bad_input(): void
    {
        $this->actingAsAdmin();
        $this->post(route('admin.merchants.store'), $this->form(['role' => 'admin']));
        $this->assertSame('merchant', User::where('email', 'asha@example.test')->sole()->role);

        $this->post(route('admin.merchants.store'), $this->form(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrorsIn('addAccount', ['email', 'password']);
    }

    public function test_only_the_admin_can_add_accounts(): void
    {
        $this->post(route('admin.merchants.store'), $this->form())->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->post(route('admin.merchants.store'), $this->form())->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'asha@example.test']);
    }

    public function test_public_signup_cannot_choose_lifetime(): void
    {
        $this->post(route('register'), $this->form(['plan' => 'lifetime']))->assertSessionHasErrors('plan');
        $this->assertDatabaseMissing('users', ['email' => 'asha@example.test']);

        $this->post(route('register'), $this->form(['plan' => 'monthly', 'subscription_status' => 'active', 'subscription_plan' => 'lifetime']));
        $user = User::where('email', 'asha@example.test')->sole();
        $this->assertSame(User::SUBSCRIPTION_PENDING, $user->subscription_status);
        $this->assertNull($user->subscription_plan);
        $this->assertFalse($user->hasSubscriptionAccess());
    }
}
