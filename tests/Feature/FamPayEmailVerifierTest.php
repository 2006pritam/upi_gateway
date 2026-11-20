<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Services\Imap\SimpleImapClient;
use App\Services\Verifiers\FamPayEmailVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FamPayEmailVerifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_fampay_credit_email_marks_order_paid(): void
    {
        $user = User::factory()->create();
        $account = $this->createFamPayAccount($user);
        $order = $this->createPendingOrder($user, $account, '149.50');

        $imap = Mockery::mock(SimpleImapClient::class);
        $imap->shouldReceive('fetchRecentFromSender')->once()->andReturn([[
            'seq' => 1,
            'headers' => $this->validHeaders(now()->toRfc2822String(), '<msg-1@fampay.in>'),
            'body' => 'You received ₹149.50 via UPI. UPI Ref No: 432512345678.',
        ]]);

        $verifier = new FamPayEmailVerifier($imap);
        $result = $verifier->check($order);

        $this->assertTrue($result->paid);
        $this->assertSame('432512345678', $result->utr);
        $this->assertSame('msg-1@fampay.in', $result->txnId);
    }

    public function test_spoofed_email_without_dkim_and_spf_pass_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = $this->createFamPayAccount($user);
        $order = $this->createPendingOrder($user, $account, '100.00');

        $imap = Mockery::mock(SimpleImapClient::class);
        $imap->shouldReceive('fetchRecentFromSender')->once()->andReturn([[
            'seq' => 1,
            'headers' => "From: FamPay <alerts@fampay.in>\r\nSubject: Payment Received\r\nDate: ".now()->toRfc2822String()."\r\nAuthentication-Results: mx.google.com; dkim=fail; spf=softfail\r\n",
            'body' => 'You received ₹100.00 via UPI. UTR: 432599999999.',
        ]]);

        $verifier = new FamPayEmailVerifier($imap);
        $result = $verifier->check($order);

        $this->assertFalse($result->paid);
    }

    public function test_outgoing_debit_email_is_ignored(): void
    {
        $user = User::factory()->create();
        $account = $this->createFamPayAccount($user);
        $order = $this->createPendingOrder($user, $account, '250.00');

        $imap = Mockery::mock(SimpleImapClient::class);
        $imap->shouldReceive('fetchRecentFromSender')->once()->andReturn([[
            'seq' => 1,
            'headers' => $this->validHeaders(now()->toRfc2822String(), '<msg-2@fampay.in>'),
            'body' => 'You paid ₹250.00 to merchant. UTR: 432511112222.',
        ]]);

        $verifier = new FamPayEmailVerifier($imap);
        $result = $verifier->check($order);

        $this->assertFalse($result->paid);
    }

    public function test_replayed_utr_from_previous_order_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = $this->createFamPayAccount($user);

        // Existing paid order already claimed UTR 432588887777.
        $paidOrder = $this->createPendingOrder($user, $account, '50.00');
        $paidOrder->forceFill([
            'status' => Order::PAID,
            'utr' => '432588887777',
            'paid_at' => now(),
        ])->save();

        $newOrder = $this->createPendingOrder($user, $account, '50.00');

        $imap = Mockery::mock(SimpleImapClient::class);
        $imap->shouldReceive('fetchRecentFromSender')->once()->andReturn([[
            'seq' => 1,
            'headers' => $this->validHeaders(now()->toRfc2822String(), '<msg-3@fampay.in>'),
            'body' => 'You received ₹50.00 via UPI. UTR: 432588887777.',
        ]]);

        $verifier = new FamPayEmailVerifier($imap);
        $result = $verifier->check($newOrder);

        $this->assertFalse($result->paid);
    }

    private function createFamPayAccount(User $user): PaymentAccount
    {
        return $user->paymentAccounts()->create([
            'provider' => 'fampay_email',
            'label' => 'FamPay Test',
            'upi_id' => 'merchant@fam',
            'payee_name' => 'Test Merchant',
            'credentials' => [
                'imap_username' => 'merchant@gmail.com',
                'imap_password' => 'abcd efgh ijkl mnop',
                'imap_host' => 'imap.gmail.com',
                'imap_port' => '993',
                'imap_sender' => 'alerts@fampay.in',
            ],
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function createPendingOrder(User $user, PaymentAccount $account, string $amount): Order
    {
        $order = new Order([
            'order_no' => 'ORD'.strtoupper(bin2hex(random_bytes(8))),
            'payment_account_id' => $account->id,
            'client_order_id' => 'CL'.bin2hex(random_bytes(4)),
            'amount' => $amount,
            'status' => Order::PENDING,
            'expires_at' => now()->addMinutes(15),
        ]);
        $order->forceFill(['environment' => Order::LIVE]);
        $order->user()->associate($user);
        $order->save();

        return $order->load('paymentAccount');
    }

    private function validHeaders(string $date, string $messageId): string
    {
        return implode("\r\n", [
            'From: FamPay <alerts@fampay.in>',
            'Subject: Money received via UPI',
            "Date: {$date}",
            "Message-ID: {$messageId}",
            'Authentication-Results: mx.google.com; dkim=pass header.i=@fampay.in; spf=pass',
        ])."\r\n";
    }
}
