<?php

namespace App\Services\Verifiers;

use App\Models\Order;
use App\Models\PaymentAccount;
use App\Support\PaytmChecksum;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Official Paytm Payment Gateway: Initiate Transaction -> Paytm's hosted
 * payment page -> Transaction Status API (v3). Every request body is signed
 * with the merchant key; status is always read from the Status API.
 */
class PaytmPgVerifier implements HostedCheckout, Verifier
{
    public function startCheckout(Order $order, string $returnUrl): array
    {
        $account = $order->paymentAccount;
        $mid = $account->credential('mid');
        $base = $this->baseUrl($account);

        $response = $this->signedPost($account, "$base/theia/api/v1/initiateTransaction?".http_build_query(['mid' => $mid, 'orderId' => $order->order_no]), [
            'requestType' => 'Payment',
            'mid' => $mid,
            'websiteName' => $account->credential('website_name') ?: 'DEFAULT',
            'orderId' => $order->order_no,
            'callbackUrl' => $returnUrl,
            'txnAmount' => ['value' => number_format((float) $order->amount, 2, '.', ''), 'currency' => 'INR'],
            'userInfo' => array_filter([
                'custId' => 'CUST'.$order->user_id.'X'.$order->id,
                'mobile' => $order->customer_mobile,
                'email' => $order->customer_email,
            ]),
        ]);

        $token = $response->json('body.txnToken');

        if ($response->json('body.resultInfo.resultStatus') !== 'S' || ! $token) {
            Log::warning('Paytm initiate transaction failed', ['order' => $order->order_no, 'body' => $response->json()]);
            throw new RuntimeException('Paytm could not start the payment: '.($response->json('body.resultInfo.resultMsg') ?? 'HTTP '.$response->status()));
        }

        return [
            'type' => 'form',
            'url' => "$base/theia/api/v1/showPaymentPage?".http_build_query(['mid' => $mid, 'orderId' => $order->order_no]),
            'fields' => ['mid' => $mid, 'orderId' => $order->order_no, 'txnToken' => $token],
            // txnToken is valid for 15 minutes.
            'expires_at' => now()->addMinutes(14)->timestamp,
        ];
    }

    public function check(Order $order): VerificationResult
    {
        $account = $order->paymentAccount;

        try {
            $response = $this->signedPost($account, $this->baseUrl($account).'/v3/order/status', [
                'mid' => $account->credential('mid'),
                'orderId' => $order->order_no,
            ]);
        } catch (Throwable $e) {
            return VerificationResult::pending(['error' => $e->getMessage()]);
        }

        $body = $response->json('body') ?? [];

        if (($body['resultInfo']['resultStatus'] ?? null) !== 'TXN_SUCCESS') {
            return VerificationResult::pending($body);
        }

        if ($this->toPaise($body['txnAmount'] ?? 0) !== $this->toPaise($order->amount)) {
            Log::warning('Paytm PG amount mismatch', ['order' => $order->order_no, 'body' => $body]);

            return VerificationResult::pending($body + ['error' => 'Amount mismatch']);
        }

        return VerificationResult::paid(
            utr: $body['bankTxnId'] ?? null,
            txnId: $body['txnId'] ?? null,
            raw: $body,
        );
    }

    /**
     * Paytm signs the exact JSON string of `body`, so encode it once and
     * embed that same string in the request.
     */
    private function signedPost(PaymentAccount $account, string $url, array $body): Response
    {
        $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES);
        $signature = PaytmChecksum::generate($bodyJson, (string) $account->credential('merchant_key'));
        $payload = '{"body":'.$bodyJson.',"head":{"signature":'.json_encode($signature).'}}';

        return Http::timeout(15)->acceptJson()->withBody($payload, 'application/json')->post($url);
    }

    private function baseUrl(PaymentAccount $account): string
    {
        return rtrim(config('gateway.paytm_pg.'.($account->credential('environment') === 'production' ? 'production' : 'sandbox')), '/');
    }

    private function toPaise(string|float|int $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
