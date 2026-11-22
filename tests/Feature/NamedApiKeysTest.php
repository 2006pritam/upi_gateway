<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\ApiKey;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NamedApiKeysTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(): User
    {
        $u = User::factory()->create();
        $u->regenerateApiCredentials();
        $u->paymentAccounts()->create(['provider' => 'manual', 'label' => 'Main', 'upi_id' => 'shop@upi', 'payee_name' => 'Shop', 'is_active' => true, 'is_default' => true]);

        return $u;
    }

    public function test_each_site_gets_its_own_key_and_secret_shown_once(): void
    {
        $u = $this->merchant();
        $this->actingAs($u)->post('/settings/api-keys', ['name' => 'Academy site', 'environment' => 'live'])->assertSessionHas('new_api_key_encrypted');
        $this->actingAs($u)->post('/settings/api-keys', ['name' => 'Shop site', 'environment' => 'live']);

        $keys = $u->apiKeys()->get();
        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0]->key, $keys[1]->key);
        $this->assertStringStartsWith('key_', $keys[0]->key);
        $this->assertStringNotContainsString('sec_', \DB::table('api_keys')->value('secret')); // stored encrypted
        // the settings page never prints a stored secret
        $this->actingAs($u)->get('/settings')->assertOk()->assertSee('Academy site')->assertDontSee($keys[0]->secret);
    }

    public function test_named_key_authenticates_and_tags_orders_and_default_key_still_works(): void
    {
        $u = $this->merchant();
        [$k, $secret] = ApiKey::issue($u, 'Academy', 'live');

        $r = $this->withHeaders(['X-Api-Key' => $k->key, 'X-Api-Secret' => $secret])->postJson('/api/v1/orders', ['amount' => 50, 'client_order_id' => 'A1'])->assertCreated();
        $this->assertSame($k->id, Order::where('client_order_id', 'A1')->value('api_key_id'));
        $this->assertNotNull($k->fresh()->last_used_at);

        $this->withHeaders(['X-Api-Key' => $u->api_key, 'X-Api-Secret' => $u->api_secret])->postJson('/api/v1/orders', ['amount' => 60, 'client_order_id' => 'D1'])->assertCreated();
        $this->assertNull(Order::where('client_order_id', 'D1')->value('api_key_id'));
        $this->actingAs($u)->get('/orders/'.$r->json('data.order_id'))->assertSee('Academy');
    }

    public function test_wrong_secret_and_other_merchants_secret_rejected(): void
    {
        $a = $this->merchant();
        $b = $this->merchant();
        [$ka] = ApiKey::issue($a, 'A', 'live');
        $this->withHeaders(['X-Api-Key' => $ka->key, 'X-Api-Secret' => $b->api_secret])->postJson('/api/v1/orders', ['amount' => 5, 'client_order_id' => 'X'])->assertUnauthorized();
        $this->withHeaders(['X-Api-Key' => $ka->key, 'X-Api-Secret' => ''])->postJson('/api/v1/orders', ['amount' => 5, 'client_order_id' => 'X'])->assertUnauthorized();
    }

    public function test_revoking_one_key_leaves_the_others_working(): void
    {
        $u = $this->merchant();
        [$k1, $s1] = ApiKey::issue($u, 'One', 'live');
        [$k2, $s2] = ApiKey::issue($u, 'Two', 'live');

        $this->actingAs($u)->delete("/settings/api-keys/{$k1->id}")->assertSessionHasNoErrors();
        $this->withHeaders(['X-Api-Key' => $k1->key, 'X-Api-Secret' => $s1])->getJson('/api/v1/accounts')->assertUnauthorized();
        $this->withHeaders(['X-Api-Key' => $k2->key, 'X-Api-Secret' => $s2])->getJson('/api/v1/accounts')->assertOk();
    }

    public function test_cannot_revoke_or_edit_another_merchants_key(): void
    {
        $a = $this->merchant();
        $b = $this->merchant();
        [$k] = ApiKey::issue($a, 'A', 'live');
        $this->actingAs($b)->delete("/settings/api-keys/{$k->id}")->assertNotFound();
        $this->actingAs($b)->post("/settings/api-keys/{$k->id}", ['webhook_url' => 'https://evil.test/x'])->assertNotFound();
        $this->assertTrue($k->fresh()->isActive());
    }

    public function test_sandbox_named_key_creates_sandbox_orders_and_can_simulate(): void
    {
        $u = $this->merchant();
        [$k, $secret] = ApiKey::issue($u, 'Test', 'sandbox');
        $this->assertStringStartsWith('test_key_', $k->key);
        $h = ['X-Api-Key' => $k->key, 'X-Api-Secret' => $secret];

        $id = $this->withHeaders($h)->postJson('/api/v1/orders', ['amount' => 20, 'client_order_id' => 'S1'])->assertCreated()->json('data.order_id');
        $this->withHeaders($h)->postJson("/api/v1/orders/{$id}/simulate")->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame('sandbox', Order::where('order_no', $id)->value('environment'));
    }

    public function test_webhook_goes_to_the_keys_own_url_signed_with_the_keys_own_secret(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $u = $this->merchant();
        $u->update(['webhook_url' => 'https://main.example.com/hook']);
        [$k, $secret] = ApiKey::issue($u, 'Site', 'live', 'https://site.example.com/hook');

        $order = app(\App\Services\OrderService::class)->create($u, ['amount' => 10, 'client_order_id' => 'W1', 'api_key_id' => $k->id]);
        $order = app(\App\Services\OrderService::class)->markPaid($order, 'UTR123123123', null, [], 'manual');
        (new SendWebhook($order->fresh(), 'order.paid', force: true))->handle();

        Http::assertSent(function ($r) use ($secret) {
            return str_starts_with($r->url(), 'https://site.example.com/hook')
                && hash_equals(hash_hmac('sha256', $r->body(), $secret), $r->header('X-Signature')[0] ?? '');
        });
        Http::assertNotSent(fn ($r) => str_starts_with($r->url(), 'https://main.example.com'));
    }

    public function test_key_without_url_falls_back_to_main_webhook_and_unsafe_urls_rejected(): void
    {
        $u = $this->merchant();
        $this->actingAs($u)->post('/settings/api-keys', ['name' => 'Bad', 'environment' => 'live', 'webhook_url' => 'http://127.0.0.1/x'])->assertSessionHasErrors();
        $this->assertCount(0, $u->apiKeys);
    }

    public function test_active_key_limit(): void
    {
        $u = $this->merchant();
        for ($i = 0; $i < ApiKey::MAX_ACTIVE_PER_USER; $i++) {
            ApiKey::issue($u, "K$i", 'live');
        }
        $this->actingAs($u)->post('/settings/api-keys', ['name' => 'Extra', 'environment' => 'live'])->assertSessionHasErrors('name');
    }
}
