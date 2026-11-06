<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Named rate limiters are not registered here because the API throttle
        // is enforced inside AuthenticateApiKey (RateLimiter::tooManyAttempts /
        // RateLimiter::hit with explicit keys).  Route-level throttle:X,Y
        // middleware handles public endpoint limits via Laravel's built-in
        // ThrottleRequests middleware.
    }
}
