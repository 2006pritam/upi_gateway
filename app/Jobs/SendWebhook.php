<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * POSTs an order event to the merchant's webhook URL, signed with
 * HMAC-SHA256 of the raw body using the merchant's API secret
 * (header: X-Signature). Retried with backoff on failure.
 */
class SendWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public Order $order, public string $event) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(): void
    {
        $merchant = $this->order->user;

        if (! $merchant->webhook_url || ! $merchant->api_secret) {
            return;
        }

        $payload = ['event' => $this->event, 'data' => $this->order->toApiArray()];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, $merchant->api_secret);

        $code = null;
        $responseBody = null;

        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-Signature' => $signature, 'X-Event' => $this->event])
                ->withBody($body, 'application/json')
                ->post($merchant->webhook_url);
            $code = $response->status();
            $responseBody = mb_substr($response->body(), 0, 2000);
        } catch (Throwable $e) {
            $responseBody = $e->getMessage();
        }

        $success = $code !== null && $code >= 200 && $code < 300;

        $this->order->webhookLogs()->create([
            'event' => $this->event,
            'url' => $merchant->webhook_url,
            'payload' => $payload,
            'response_code' => $code,
            'response_body' => $responseBody,
            'success' => $success,
            'attempt' => $this->attempts(),
        ]);

        if (! $success) {
            throw new RuntimeException("Webhook to {$merchant->webhook_url} failed with ".($code ?? 'no response'));
        }
    }
}
