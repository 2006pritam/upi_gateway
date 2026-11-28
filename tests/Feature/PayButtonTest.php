<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayButtonTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->paymentAccounts()->create(['provider' => 'manual', 'label' => 'Main', 'upi_id' => 'shop@upi', 'payee_name' => 'Shop', 'is_active' => true]);

        return $user;
    }

    public function test_merchant_sets_the_look_and_gets_button_code(): void
    {
        $user = $this->merchant();
        $this->actingAs($user)->post('/links', ['title' => 'Fee', 'amount' => '499'])->assertSessionHasNoErrors();
        $link = $user->paymentLinks()->firstOrFail();

        $this->actingAs($user)->put("/links/{$link->code}", ['description' => 'Batch of 2027', 'button_label' => 'Enrol', 'theme_color' => '#112233'])
            ->assertSessionHasNoErrors();
        $this->actingAs($user)->get('/links')->assertOk()->assertSee('upi-button.js')->assertSee('#112233');
        $this->get("/p/{$link->code}")->assertOk()->assertSee('Batch of 2027')->assertSee('Enrol')->assertSee('#112233');
    }

    public function test_bad_colour_and_other_merchants_are_rejected(): void
    {
        $user = $this->merchant();
        $link = $user->paymentLinks()->create(['title' => 'Fee']);

        $this->actingAs($user)->put("/links/{$link->code}", ['theme_color' => 'red;x:y'])->assertSessionHasErrors('theme_color');
        $this->actingAs(User::factory()->create())->put("/links/{$link->code}", ['button_label' => 'Mine'])->assertNotFound();
        $this->assertNull($link->refresh()->button_label);
    }

    public function test_result_is_posted_to_the_opening_website(): void
    {
        $user = $this->merchant(['redirect_domains' => ['shop.example']]);
        $link = $user->paymentLinks()->create(['title' => 'Fee', 'amount' => 10]);

        $this->post("/p/{$link->code}", ['embed_origin' => 'https://shop.example'])->assertRedirect();
        $order = Order::sole();
        $this->get(route('pay.show', $order))->assertOk()->assertDontSee('postMessage');

        $order->forceFill(['status' => Order::PAID, 'paid_at' => now(), 'redirect_url' => 'https://shop.example/thanks'])->save();
        $this->get(route('pay.show', $order))->assertOk()
            ->assertSee('postMessage')->assertSee('"https:\/\/shop.example"', false)->assertSee('Return to');
        $this->getJson(route('pay.status', $order))->assertJson(['redirect' => null]);
    }

    public function test_normal_visits_post_nothing_and_still_redirect(): void
    {
        $user = $this->merchant(['redirect_domains' => ['shop.example']]);
        $link = $user->paymentLinks()->create(['title' => 'Fee', 'amount' => 10]);

        $this->post("/p/{$link->code}", ['embed_origin' => '*'])->assertRedirect();
        $order = Order::sole();
        $order->forceFill(['status' => Order::PAID, 'paid_at' => now(), 'redirect_url' => 'https://shop.example/thanks'])->save();

        $this->get(route('pay.show', $order))->assertOk()->assertDontSee('postMessage');
        $this->assertStringContainsString('shop.example/thanks', $this->getJson(route('pay.status', $order))->json('redirect'));
    }
}
