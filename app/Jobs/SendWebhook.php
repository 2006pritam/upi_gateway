<?php

namespace App\Jobs;

use App\Models\Order;
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

        // ── Re-validate URL at send time (DNS rebinding defence) ──────────────
        try {
            $safe = app(SafeUrl::class)->check($merchant->webhook_url);
        } catch (ValidationException) {
            // URL is now unsafe (e.g. DNS rebind). Log and bail — do NOT retry.
            $this->order->webhookLogs()->create([
                'event'         => $this->event,
                'url'           => $merchant->webhook_url,
                'payload'       => ['event' => $this->event, 'data' => $this->order->toApiArray()],
                'response_code' => null,
                'response_body' => null,
                'success'       => false,
                'attempt'       => $this->attempts(),
                'error_label'   => 'blocked_url',
            ]);
            // Return without throwing → queue does NOT retry this job.
            return;
        }

        // ── Build and sign the payload ─────────────────────────────────────────
        $payload   = ['event' => $this->event, 'data' => $this->order->toApiArray()];
        $body      = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, $merchant->api_secret);

        // ── CURLOPT_RESOLVE pins the connection to the validated IP ────────────
        // Format: "hostname:port:ip" — prevents curl from re-resolving.
        $resolveEntry = "{$safe['host']}:{$safe['port']}:{$safe['ips'][0]}";

        $code       = null;
        $errorLabel = null;

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withHeaders(['X-Signature' => $signature, 'X-Event' => $this->event])
                ->withBody($body, 'application/json')
                ->withOptions(['curl' => [CURLOPT_RESOLVE => [$resolveEntry]]])
                ->post($merchant->webhook_url);

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
            'event'         => $this->event,
            'url'           => $merchant->webhook_url,
            'payload'       => $payload,
            'response_code' => $code,
            'response_body' => null,
            'success'       => $success,
            'attempt'       => $this->attempts(),
            'error_label'   => $errorLabel,
        ]);

        if (! $success) {
            throw new RuntimeException(
                "Webhook to {$merchant->webhook_url} failed with ".($code ?? 'no response')
            );
        }
    }
}
