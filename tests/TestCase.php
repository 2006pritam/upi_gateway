<?php

namespace Tests;

use App\Support\SafeUrl;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Disable rate limiting in tests by setting very high limits.
        config([
            'gateway.api_key_rate_limit' => 100000,
            'gateway.api_ip_rate_limit'  => 100000,
        ]);

        $this->app->bind(SafeUrl::class, fn () => new SafeUrl(
            resolver: fn (string $host) => ['93.184.216.34']
        ));
    }
}
