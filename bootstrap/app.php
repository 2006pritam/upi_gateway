<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin'   => \App\Http\Middleware\EnsureAdmin::class,
            'active'  => \App\Http\Middleware\EnsureActive::class,
            'api.key' => \App\Http\Middleware\AuthenticateApiKey::class,
        ]);

        // AuthenticateSession invalidates sessions when the user's password hash
        // changes, covering password changes AND API-key regeneration (which
        // touches the password column to bump the hash seen by the middleware).
        // It must come AFTER StartSession and AFTER Auth middleware.
        $middleware->web(append: [
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        $middleware->redirectUsersTo('/dashboard');

        // Paytm PG POSTs the customer back here from its own domain.
        $middleware->validateCsrfTokens(except: ['pay/*/return']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
