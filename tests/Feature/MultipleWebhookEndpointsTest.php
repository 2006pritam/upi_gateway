<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\Order;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MultipleWebhookEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $email = 'multi@example.com'): User
    {
        $merchant = User::create([
            'name' => 'Multi Merchant',
            'business_name' => 'Multi Shop',
            'email' => $email,
            'subscription_status' => User::SUBSCRIPTION_ACTIVE,
            'password' => 'password12345',
        ]);
        $merchant->regenerateApiCredentials();
        $merchant->paymentAccounts()->create([
            'provider' => 'manual',
            'label' => 'Live account',
            'upi_id' => 'live@upi',
            'payee_name' => 'Live Shop',
            'is_default' => true,
        ]);

        return $merchant->fresh();
    }

    private function paidOrder(User $merchant): Order
    {
        return $merchant->orders()->create([
            'order_no' => 'ORD'.uniqid(),
            'client_order_id' => 'C-'.uniqid(),
            'amount' => 100,
            'status' => Order::PAID,
            'environment' => Order::LIVE,
            'payment_account_id' => $merchant->paymentAccounts()->first()->id,
        ])->fresh();
    }

    public function test_job_fans_out_to_each_additional_endpoint_once(): void
    {
        Queue::fake();
        $merchant = $this->merchant();
        $merchant->update(['webhook_url' => 'https://primary.example/hook']);
        $merchant->webhookEndpoints()->createMany([
            ['environment' => 'live', 'url' => 'https://second.example/hook'],
            ['environment' => 'live', 'url' => 'https://third.example/hook'],
            ['environment' => 'sandbox', 'url' => 'https://sandbox-only.example/hook'],
            ['environment' => 'live', 'url' => 'https://primary.example/hook/'], // duplicate of primary
        ]);
        Http::fake();

        (new SendWebhook($this->paidOrder($merchant), 'order.paid'))->handle();

        // Primary was delivered directly; two children for the live extras only.
        Queue::assertPushed(SendWebhook::class, 2);
        Queue::assertPushed(SendWebhook::class, fn ($job) => $job->endpointId !== null);
    }

    public function test_every_endpoint_receives_a_signed_delivery_and_failures_are_independent(): void
    {
        $merchant = $this->merchant();
        $merchant->update(['webhook_url' => 'https://primary.example/hook']);
        $second = $merchant->webhookEndpoints()->create(['environment' => 'live', 'url' => 'https://second.example/hook']);
        $order = $this->paidOrder($merchant);

        Http::fake([
            'primary.example/*' => Http::response('ok', 200),
            'second.example/*' => Http::response('nope', 200),
        ]);

        (new SendWebhook($order, 'order.paid', $second->id))->handle();
        (new SendWebhook($order, 'order.paid'))->handle();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://second.example/hook'
            && hash_equals(hash_hmac('sha256', $r->body(), $merchant->api_secret), $r->header('X-Signature')[0]));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://primary.example/hook');
        $this->assertSame(2, $order->webhookLogs()->where('success', true)->count());
    }

    public function test_one_endpoint_succeeding_does_not_suppress_delivery_to_another(): void
    {
        $merchant = $this->merchant();
        $merchant->update(['webhook_url' => 'https://primary.example/hook']);
        $second = $merchant->webhookEndpoints()->create(['environment' => 'live', 'url' => 'https://second.example/hook']);
        $order = $this->paidOrder($merchant);
        Http::fake(['*' => Http::response('ok', 200)]);

        (new SendWebhook($order, 'order.paid'))->handle();
        (new SendWebhook($order, 'order.paid', $second->id))->handle();
        // Redelivery of an already-successful endpoint is skipped.
        (new SendWebhook($order, 'order.paid', $second->id))->handle();

        $this->assertSame(2, $order->webhookLogs()->count());
    }

    public function test_deleted_endpoint_is_skipped(): void
    {
        $merchant = $this->merchant();
        $endpoint = $merchant->webhookEndpoints()->create(['environment' => 'live', 'url' => 'https://gone.example/hook']);
        $order = $this->paidOrder($merchant);
        $endpoint->delete();
        Http::fake();

        (new SendWebhook($order, 'order.paid', $endpoint->id))->handle();

        Http::assertNothingSent();
    }

    public function test_merchant_can_add_and_remove_endpoints_from_settings(): void
    {
        $merchant = $this->merchant();

        $this->actingAs($merchant)->post(route('settings.webhook-endpoints.store'), [
            'environment' => 'live',
            'url' => 'https://second.example/hook',
        ])->assertSessionHasNoErrors();

        $endpoint = WebhookEndpoint::firstOrFail();
        $this->assertSame($merchant->id, $endpoint->user_id);
        $this->actingAs($merchant)->get(route('settings'))->assertOk()->assertSee('https://second.example/hook');

        $this->actingAs($merchant)->delete(route('settings.webhook-endpoints.destroy', $endpoint))->assertRedirect();
        $this->assertSame(0, WebhookEndpoint::count());
    }

    public function test_validation_rules(): void
    {
        $merchant = $this->merchant();
        $merchant->update(['webhook_url' => 'https://primary.example/hook']);
        $post = fn (array $d) => $this->actingAs($merchant)->post(route('settings.webhook-endpoints.store'), $d);

        // Unsafe URL is rejected.
        $post(['environment' => 'live', 'url' => 'http://127.0.0.1/hook'])->assertSessionHasErrors('webhook_url');
        // Same as the primary URL.
        $post(['environment' => 'live', 'url' => 'https://primary.example/hook/'])->assertSessionHasErrors('url');
        // Bad environment.
        $post(['environment' => 'staging', 'url' => 'https://x.example/hook'])->assertSessionHasErrors('environment');

        // Duplicate extra endpoint and the per-environment cap.
        $post(['environment' => 'live', 'url' => 'https://e1.example/hook'])->assertSessionHasNoErrors();
        $post(['environment' => 'live', 'url' => 'https://e1.example/hook'])->assertSessionHasErrors('url');
        foreach ([2, 3, 4] as $n) {
            $post(['environment' => 'live', 'url' => "https://e{$n}.example/hook"])->assertSessionHasNoErrors();
        }
        $post(['environment' => 'live', 'url' => 'https://e5.example/hook'])->assertSessionHasErrors('url');
        $this->assertSame(User::MAX_EXTRA_WEBHOOKS, $merchant->webhookEndpoints()->count());

        // Sandbox endpoints must not reuse a live URL.
        $post(['environment' => 'sandbox', 'url' => 'https://e1.example/hook'])->assertSessionHasErrors('url');
    }

    public function test_merchant_cannot_delete_another_merchants_endpoint(): void
    {
        $owner = $this->merchant('owner@example.com');
        $other = $this->merchant('other@example.com');
        $endpoint = $owner->webhookEndpoints()->create(['environment' => 'live', 'url' => 'https://owner.example/hook']);

        $this->actingAs($other)->delete(route('settings.webhook-endpoints.destroy', $endpoint))->assertNotFound();
        $this->assertSame(1, WebhookEndpoint::count());
    }
}
