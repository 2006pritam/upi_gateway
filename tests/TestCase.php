<?php

namespace Tests;

use App\Support\SafeUrl;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $installedPath = storage_path('framework/testing/installed');
        config(['installer.installed_path' => $installedPath]);
        if (! is_dir(dirname($installedPath))) {
            mkdir(dirname($installedPath), 0775, true);
        }
        file_put_contents($installedPath, 'test installation');

        // Disable rate limiting in tests by setting very high limits.
        config([
            'gateway.api_key_rate_limit' => 100000,
            'gateway.api_ip_rate_limit' => 100000,
        ]);

        $this->app->bind(SafeUrl::class, fn () => new SafeUrl(
            resolver: fn (string $host) => ['93.184.216.34']
        ));
    }
}
