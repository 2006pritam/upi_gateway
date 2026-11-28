<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->isAdmin()) {
            return $next($request);
        }

        if ($user->subscriptionHasExpired()) {
            $user->forceFill(['subscription_status' => User::SUBSCRIPTION_EXPIRED])->save();
        }

        if (! $user->hasSubscriptionAccess()) {
            return redirect()->route('subscription.show');
        }

        return $next($request);
    }
}
