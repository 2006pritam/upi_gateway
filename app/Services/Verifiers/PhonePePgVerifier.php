<?php

namespace App\Services\Verifiers;

use App\Models\Order;
use App\Models\PaymentAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Official PhonePe PG, Standard Checkout v2 (OAuth client credentials).
 * Our order_no is sent as merchantOrderId; status is always read back from
 * PhonePe's Order Status API, never trusted from the redirect.
 */
class PhonePePgVerifier implements HostedCheckout, Verifier
{
    public function startCheckout(Order $order, string $returnUrl): array
    {
        $secondsLeft = (int) now()->diffInSeconds($order->expires_at, false);

        $response = $this->request($order->paymentAccount, fn (PendingRequest $http, string $base) => $http->post("$base/checkout/v2/pay", [
            'merchantOrderId' => $order->order_no,
            'amount' => $this->toPaise($order->amount),
            'expireAfter' => max(300, min(3600, $secondsLeft)),
            'paymentFlow' => [
                'type' => 'PG_CHECKOUT',
                'message' => $order->note ?: 'Payment for '.$order->client_order_id,
                'merchantUrls' => ['redirectUrl' => $returnUrl],
            ],
            'metaInfo' => ['udf1' => $order->client_order_id],
        ]));

        $url = $response->json('redirectUrl');

        if (! $response->successful() || ! $url) {
            Log::warning('PhonePe create payment failed', ['order' => $order->order_no, 'status' => $response->status(), 'body' => $response->json()]);
            throw new RuntimeException('PhonePe could not start the payment: '.($response->json('message') ?? 'HTTP '.$response->status()));
        }

        return [
            'type' => 'redirect',
            'url' => $url,
            'expires_at' => (int) (($response->json('expireAt') ?? now()->addMinutes(15)->getTimestampMs()) / 1000),
        ];
    }

    public function check(Order $order): VerificationResult
    {
        try {
            $response = $this->request($order->paymentAccount, fn (PendingRequest $http, string $base) => $http->get("$base/checkout/v2/order/{$order->order_no}/status", ['details' => 'false']));
        } catch (Throwable $e) {
            return VerificationResult::pending(['error' => $e->getMessage()]);
        }

        $data = $response->json() ?? [];

        if (($data['state'] ?? null) !== 'COMPLETED') {
            return VerificationResult::pending($data);
        }

        if ((int) ($data['amount'] ?? 0) !== $this->toPaise($order->amount)) {
            Log::warning('PhonePe amount mismatch', ['order' => $order->order_no, 'response' => $data]);

            return VerificationResult::pending($data + ['error' => 'Amount mismatch']);
        }

        $payment = collect($data['paymentDetails'] ?? [])->firstWhere('state', 'COMPLETED') ?? [];

        return VerificationResult::paid(
            utr: $payment['rail']['utr'] ?? null,
            txnId: $payment['transactionId'] ?? ($data['orderId'] ?? null),
            raw: $data,
        );
    }

    /**
     * Run an authenticated call; on 401 refresh the cached token and retry once.
     *
     * @param  callable(PendingRequest, string): Response  $call
     */
    private function request(PaymentAccount $account, callable $call): Response
    {
        $urls = config('gateway.phonepe_pg.'.$this->env($account));

        foreach ([false, true] as $refresh) {
            $http = Http::timeout(15)->acceptJson()->asJson()
                ->withHeaders(['Authorization' => 'O-Bearer '.$this->token($account, $urls['token_url'], $refresh)]);

            $response = $call($http, $urls['base_url']);

            if ($response->status() !== 401) {
                return $response;
            }
        }

        return $response;
    }

    private function token(PaymentAccount $account, string $tokenUrl, bool $refresh): string
    {
        $cacheKey = 'phonepe_token:'.$account->id.':'.md5($account->credential('client_id').$account->credential('client_secret'));

        if (! $refresh && ($token = Cache::get($cacheKey))) {
            return $token;
        }

        $response = Http::timeout(15)->asForm()->acceptJson()->post($tokenUrl, [
            'client_id' => $account->credential('client_id'),
            'client_version' => $account->credential('client_version'),
            'client_secret' => $account->credential('client_secret'),
            'grant_type' => 'client_credentials',
        ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! $token) {
            throw new RuntimeException('PhonePe login failed. Check Client ID, Client Secret, Client Version and environment.');
        }

        // Refresh a minute before PhonePe's expiry.
        $ttl = max(60, (int) $response->json('expires_at', time() + 900) - time() - 60);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    private function env(PaymentAccount $account): string
    {
        return $account->credential('environment') === 'production' ? 'production' : 'sandbox';
    }

    private function toPaise(string|float|int $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
