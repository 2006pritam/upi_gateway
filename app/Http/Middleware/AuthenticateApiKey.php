<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Merchant API auth: headers `X-Api-Key` and `X-Api-Secret`.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('X-Api-Key');
        $secret = (string) $request->header('X-Api-Secret');

        $merchant = $key !== '' ? User::where('api_key', $key)->first() : null;

        if (! $merchant || ! $merchant->api_secret || ! hash_equals($merchant->api_secret, $secret)) {
            return response()->json(['message' => 'Invalid API key or secret.'], 401);
        }

        if (! $merchant->is_active) {
            return response()->json(['message' => 'Merchant account is disabled.'], 403);
        }

        $request->setUserResolver(fn () => $merchant);

        return $next($request);
    }
}
