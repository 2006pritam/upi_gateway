<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Order;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Merchant API authentication via X-Api-Key / X-Api-Secret headers.
 *
 * Enforces:
 *  - Per-API-key rate limit  : config('gateway.api_key_rate_limit') / min
 *  - Per-IP rate limit       : config('gateway.api_ip_rate_limit') / min
 *  - X-Request-Id on every response
 *  - last_used_at / last_used_ip at most once per minute
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->headers->set('X-Request-Id', $requestId);

        $key = (string) $request->header('X-Api-Key');
        $secret = (string) $request->header('X-Api-Secret');

        $environment = str_starts_with($key, 'test_key_') ? Order::SANDBOX : Order::LIVE;
        $apiKey = null;
        $merchant = null;
        $expectedSecret = null;

        if ($key !== '') {
            // Named keys first; the merchant's original keys (users table) keep working as the "Default key".
            $apiKey = ApiKey::where('key', $key)->whereNull('revoked_at')->with('user')->first();
            if ($apiKey) {
                $merchant = $apiKey->user;
                $environment = $apiKey->environment;
                $expectedSecret = $apiKey->secret;
            } else {
                $keyColumn = $environment === Order::SANDBOX ? 'sandbox_api_key' : 'api_key';
                $secretColumn = $environment === Order::SANDBOX ? 'sandbox_api_secret' : 'api_secret';
                $merchant = User::where($keyColumn, $key)->first();
                $expectedSecret = $merchant?->{$secretColumn};
            }
        }

        if (! $merchant || ! $expectedSecret || ! hash_equals($expectedSecret, $secret)) {
            return response()->json(['message' => 'Invalid API key or secret.'], 401)
                ->header('X-Request-Id', $requestId);
        }

        if (! $merchant->is_active) {
            return response()->json(['message' => 'Merchant account is disabled.'], 403)
                ->header('X-Request-Id', $requestId);
        }

        $request->setUserResolver(fn () => $merchant);
        $request->attributes->set('payment_environment', $environment);
        $request->attributes->set('api_key_id', $apiKey?->id);

        // ── Per-key throttle ──────────────────────────────────────────────────
        $keyLimit = (int) config('gateway.api_key_rate_limit', 120);
        $keyBucket = $apiKey
            ? 'api-key:k'.$apiKey->id
            : ($environment === Order::LIVE ? 'api-key:'.$merchant->id : 'api-key:sandbox:'.$merchant->id);

        if (RateLimiter::tooManyAttempts($keyBucket, $keyLimit)) {
            return response()->json(['message' => 'Rate limit exceeded for this API key.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($keyBucket))
                ->header('X-RateLimit-Limit', (string) $keyLimit)
                ->header('X-RateLimit-Remaining', '0')
                ->header('X-Request-Id', $requestId);
        }
        RateLimiter::hit($keyBucket, 60);

        // ── Per-IP throttle ───────────────────────────────────────────────────
        $ipLimit = (int) config('gateway.api_ip_rate_limit', 600);
        $ipBucket = 'api-ip:'.$request->ip();

        if (RateLimiter::tooManyAttempts($ipBucket, $ipLimit)) {
            return response()->json(['message' => 'Rate limit exceeded for this IP.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($ipBucket))
                ->header('X-Request-Id', $requestId);
        }
        RateLimiter::hit($ipBucket, 60);

        // ── Track last_used_at / last_used_ip (at most once per minute) ────────
        $trackKey = $apiKey
            ? 'api_track:k'.$apiKey->id
            : ($environment === Order::LIVE ? 'api_track:'.$merchant->id : 'api_track:sandbox:'.$merchant->id);
        if (! app('cache')->has($trackKey)) {
            app('cache')->put($trackKey, 1, 60);
            DB::table($apiKey ? 'api_keys' : 'users')->where('id', $apiKey?->id ?? $merchant->id)->update(
                $apiKey
                    ? ['last_used_at' => now(), 'last_used_ip' => $request->ip()]
                    : ['api_last_used_at' => now(), 'api_last_used_ip' => $request->ip()]
            );
        }

        $response = $next($request);

        return $response->header('X-Request-Id', $requestId);
    }
}
