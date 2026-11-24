<?php

namespace App\Providers;

use App\Models\PaymentAccount;
use App\Policies\PaymentAccountPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Hosts without pdo_pgsql (e.g. Appwrite Functions) use a pure-PHP PostgreSQL driver.
        if (filter_var(env('DB_PURE_PHP', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->app->bind('db.connector.pgsql', fn () => new \App\Support\PurePg\PurePgConnector);
        }
    }

    public function boot(): void
    {
        // Never render detailed exception pages in production, even if an
        // environment file was accidentally deployed with APP_DEBUG=true.
        if ($this->app->isProduction()) {
            config(['app.debug' => false]);
        }

        // Named rate limiters are not registered here because the API throttle
        // is enforced inside AuthenticateApiKey (RateLimiter::tooManyAttempts /
        // RateLimiter::hit with explicit keys).  Route-level throttle:X,Y
        // middleware handles public endpoint limits via Laravel's built-in
        // ThrottleRequests middleware.

        Gate::policy(PaymentAccount::class, PaymentAccountPolicy::class);

        view()->share('csp_nonce', '');
    }
}
