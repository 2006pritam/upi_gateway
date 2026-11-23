<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\WebhookEndpoint;
use App\Support\SafeUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * POSTs an order event to the merchant's webhook URL, signed with
 * HMAC-SHA256 of the raw body using the merchant's API secret
 * (header: X-Signature). Retried with backoff on failure.
 *
 * SSRF mitigations applied here:
 *   1. URL is re-validated by SafeUrl at send time (guards against DNS rebinding).
 *   2. The connection is pinned to the validated IP via CURLOPT_RESOLVE so curl
 *      cannot re-resolve the hostname to a different address mid-flight.
 *   3. Redirects are disabled (withoutRedirecting()) to prevent redirect chains
 *      that land on internal addresses.
 *   4. The response body is never stored; only the status code (or a short error
 *      label) is persisted in webhook_logs.
 */
class SendWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /**
     * $endpointId null = the primary URL (the key's own URL or the merchant-wide one). On the first,
     *                    non-forced attempt this job also queues one job per additional endpoint.
     * $endpointId set  = deliver to that one additional endpoint only.
     * Each endpoint is its own job, so one failing URL never blocks or re-sends the others.
     */
    public function __construct(public Order $order, public string $event, public bool $force = false, public ?int $endpointId = null) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(): void
    {
        $merchant = $this->order->user;
        $sandbox = $this->order->environment === Order::SANDBOX;
        $webhookUrl = $sandbox ? $merchant->sandbox_webhook_url : $merchant->webhook_url;
        $signingSecret = $sandbox ? $merchant->sandbox_api_secret : $merchant->api_secret;

        // Orders created with a named key notify that key's own URL (if set) and are signed with that key's secret,
        // so each website verifies webhooks with the secret it already holds.
        $apiKey = $this->order->api_key_id ? $this->order->apiKey : null;
        if ($apiKey) {
            $webhookUrl = $apiKey->webhook_url ?: $webhookUrl;
            $signingSecret = $apiKey->secret;
        }

        if (! $signingSecret) {
            return;
        }

        if ($this->endpointId === null) {
            if (! $this->force && $this->attempts() === 1) {
                $this->fanOut($merchant->id, $sandbox, $webhookUrl);
            }
        } else {
            $endpoint = WebhookEndpoint::query()
                ->where('user_id', $merchant->id)
                ->where('environment', $sandbox ? Order::SANDBOX : Order::LIVE)
                ->find($this->endpointId);

            // Endpoint was deleted after the job was queued: nothing to deliver.
            $webhookUrl = $endpoint?->url;
        }

        if (! $webhookUrl) {
            return;
        }

        if ($this->endpointId === null && $sandbox && $merchant->webhook_url
            && hash_equals(rtrim($merchant->webhook_url, '/'), rtrim($webhookUrl, '/'))) {
            return;
        }

        // ── Idempotency: skip if a successful delivery already exists ──────────
        // Prevents double-delivery when the queue retries after a worker crash
        // that occurred after the HTTP call succeeded but before job deletion.
        if (! $this->force && $this->order->webhookLogs()
            ->where('event', $this->event)
            ->where('url', $webhookUrl)
            ->where('success', true)
            ->exists()) {
            return;
        }

        // ── Re-validate URL at send time (DNS rebinding defence) ──────────────
        try {
            $safe = app(SafeUrl::class)->check($webhookUrl);
        } catch (ValidationException) {
            // URL is now unsafe (e.g. DNS rebind). Log and bail — do NOT retry.
            $this->order->webhookLogs()->create([
                'event' => $this->event,
                'url' => $webhookUrl,
                'payload' => ['event' => $this->event, 'data' => $this->order->toApiArray()],
                'response_code' => null,
                'response_body' => null,
                'success' => false,
                'attempt' => $this->attempts(),
                'error_label' => 'blocked_url',
            ]);

            // Return without throwing → queue does NOT retry this job.
            return;
        }

        // ── Build and sign the payload ─────────────────────────────────────────
        $payload = ['event' => $this->event, 'data' => $this->order->toApiArray()];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, $signingSecret);

        // ── CURLOPT_RESOLVE pins the connection to the validated IP ────────────
        // Format: "hostname:port:ip" — prevents curl from re-resolving.
        $resolveEntry = "{$safe['host']}:{$safe['port']}:{$safe['ips'][0]}";

        $code = null;
        $errorLabel = null;

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withHeaders(['X-Signature' => $signature, 'X-Event' => $this->event])
                ->withBody($body, 'application/json')
                ->withOptions(['curl' => [CURLOPT_RESOLVE => [$resolveEntry]]])
                ->post($webhookUrl);

            $code = $response->status();

            if ($code >= 500) {
                $errorLabel = 'http_'.$code;
            }
        } catch (Throwable $e) {
            // Distinguish timeout from other network errors for the log label.
            $lower = strtolower($e->getMessage());
            if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
                $errorLabel = 'timeout';
            } else {
                $errorLabel = 'network_error';
            }
        }

        $success = $code !== null && $code >= 200 && $code < 300;

        // Store only the status code and a short error label — never the body.
        $this->order->webhookLogs()->create([
            'event' => $this->event,
            'url' => $webhookUrl,
            'payload' => $payload,
            'response_code' => $code,
            'response_body' => null,
            'success' => $success,
            'attempt' => $this->attempts(),
            'error_label' => $errorLabel,
        ]);

        if (! $success) {
            throw new RuntimeException(
                'Webhook delivery failed with '.($code ?? 'no response')
            );
        }
    }

    /** Queue one delivery job per additional endpoint of this order's environment. */
    private function fanOut(int $merchantId, bool $sandbox, ?string $primaryUrl): void
    {
        $endpoints = WebhookEndpoint::query()
            ->where('user_id', $merchantId)
            ->where('environment', $sandbox ? Order::SANDBOX : Order::LIVE)
            ->get();

        foreach ($endpoints as $endpoint) {
            // Same URL as the primary one: it is already being delivered to.
            if ($primaryUrl && hash_equals(rtrim($primaryUrl, '/'), rtrim($endpoint->url, '/'))) {
                continue;
            }

            try {
                static::dispatch($this->order, $this->event, false, $endpoint->id);
            } catch (Throwable $e) {
                // With the sync queue a failing endpoint throws here; it must not
                // stop the primary delivery or the other endpoints.
                report($e);
            }
        }
    }
}
