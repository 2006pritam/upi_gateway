<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Plan gating on the surfaces that exist beyond the dashboard and API. */
class SubscriptionGatingTest extends TestCase
{
    use RefreshDatabase;

    private function unpaid(): User
    {
        return User::factory()->create(['subscription_status' => User::SUBSCRIPTION_PENDING]);
    }

    private function withAccount(User $user): User
    {
        $user->paymentAccounts()->create(['provider' => 'manual', 'label' => 'Main', 'upi_id' => 'shop@upi', 'payee_name' => 'Shop', 'is_active' => true]);

        return $user;
    }

    private function headers(string $key, string $secret): array
    {
        return ['X-Api-Key' => $key, 'X-Api-Secret' => $secret, 'Accept' => 'application/json'];
    }

    public function test_admin_without_a_plan_keeps_web_access_and_both_kinds_of_api_key(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'subscription_status' => User::SUBSCRIPTION_PENDING]);
        $admin->regenerateApiCredentials();
        [$named, $namedSecret] = ApiKey::issue($admin, 'My site', 'live');

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $this->getJson('/api/v1/accounts', $this->headers($admin->api_key, $admin->api_secret))->assertOk();
        $this->getJson('/api/v1/accounts', $this->headers($named->key, $namedSecret))->assertOk();
    }

    public function test_merchant_without_a_plan_cannot_use_a_named_api_key(): void
    {
        [$key, $secret] = ApiKey::issue($this->unpaid(), 'Shop', 'live');

        $this->getJson('/api/v1/accounts', $this->headers($key->key, $secret))->assertForbidden();
    }

    public function test_newer_pages_send_an_unpaid_merchant_to_the_plan_page(): void
    {
        $this->actingAs($this->unpaid());

        foreach (['links.index', 'subscriptions.index', 'earnings.index', 'reports.index', 'webhooks.index', 'settings'] as $name) {
            $this->get(route($name))->assertRedirect(route('subscription.show'));
        }
    }

    public function test_payment_link_stops_taking_orders_when_the_plan_lapses(): void
    {
        $merchant = $this->withAccount(User::factory()->create(['subscription_expires_at' => now()->addDay()]));
        $link = $merchant->paymentLinks()->create(['title' => 'Fees', 'amount' => 100]);

        $this->get(route('link.show', $link))->assertOk();

        $this->travel(2)->days();

        $this->get(route('link.show', $link))->assertNotFound();
        $this->post(route('link.pay', $link))->assertNotFound();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_recurring_invoices_are_not_created_for_a_merchant_without_a_plan(): void
    {
        $merchant = $this->withAccount($this->unpaid());
        $merchant->subscriptions()->create([
            'plan_name' => 'Monthly fee', 'customer_name' => 'Asha', 'amount' => 100,
            'interval' => 'monthly', 'status' => 'active', 'next_due_at' => today(),
        ]);

        $this->artisan('subscriptions:bill')->assertSuccessful();

        $this->assertDatabaseCount('orders', 0);
    }
}
