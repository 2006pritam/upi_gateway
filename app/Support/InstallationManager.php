<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class InstallationManager
{
    public function hasAdminUser(): bool
    {
        try {
            if (Schema::hasTable('installation_guards')
                && DB::table('installation_guards')->where('name', 'primary_admin')->exists()) {
                return true;
            }

            return Schema::hasTable('users')
                && DB::table('users')->where('role', 'admin')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public function isInstalled(): bool
    {
        if (is_file($this->installedPath())) {
            return true;
        }

        return $this->hasAdminUser();
    }

    public function databaseIsMigrated(): bool
    {
        try {
            if (! Schema::hasTable('migrations')) {
                return false;
            }

            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                return false;
            }

            $migrationFiles = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
            if (empty($migrationFiles)) {
                return false;
            }

            $ran = $migrator->getRepository()->getRan();
            if (empty($ran)) {
                return false;
            }

            $pending = array_diff($migrationFiles, $ran);
            if (! empty($pending)) {
                return false;
            }

            $requiredTables = [
                'users',
                'cache',
                'jobs',
                'payment_accounts',
                'orders',
                'webhook_logs',
                'admin_audit_logs',
                'installation_guards',
            ];

            foreach ($requiredTables as $table) {
                if (! Schema::hasTable($table)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Database drivers the application supports. */
    public const SUPPORTED_DRIVERS = ['mysql', 'pgsql'];

    public static function defaultPort(?string $driver): int
    {
        return $driver === 'pgsql' ? 5432 : 3306;
    }

    public function configureDatabase(array $data): void
    {
        $driver = $data['db_connection'] ?? null;

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw new RuntimeException('Only MySQL and PostgreSQL are supported as the application database.');
        }

        config([
            'database.default' => $driver,
            "database.connections.{$driver}.host" => $data['db_host'],
            "database.connections.{$driver}.port" => $data['db_port'],
            "database.connections.{$driver}.database" => $data['db_database'],
            "database.connections.{$driver}.username" => $data['db_username'],
            "database.connections.{$driver}.password" => $data['db_password'] ?? '',
        ]);

        DB::purge($driver);
    }

    public function testDatabaseConnection(array $data): void
    {
        $driver = $data['db_connection'] ?? 'mysql';
        $originalDefault = config('database.default');
        $originalConnection = config("database.connections.{$driver}");

        try {
            $this->configureDatabase($data);
            DB::connection($driver)->select('SELECT 1');
        } catch (Throwable $e) {
            DB::purge($driver);
            config([
                'database.default' => $originalDefault,
                "database.connections.{$driver}" => $originalConnection,
            ]);

            throw $e;
        }
    }

    public function writeEnvironment(array $data): void
    {
        $path = $this->envPath();
        $template = base_path('.env.example');
        $env = is_file($path) ? file_get_contents($path) : file_get_contents($template);

        if ($env === false) {
            throw new RuntimeException('The environment template could not be read.');
        }

        $existingKey = $this->valueFromEnvironment($env, 'APP_KEY');
        $appKey = $existingKey !== ''
            ? $existingKey
            : 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));

        $replacements = [
            'APP_NAME' => $this->envValue($data['app_name']),
            'APP_ENV' => 'production',
            'APP_KEY' => $appKey,
            'APP_DEBUG' => 'false',
            'APP_URL' => $this->envValue($data['app_url']),
            'LOG_LEVEL' => 'error',
            'SESSION_SECURE_COOKIE' => str_starts_with(strtolower($data['app_url']), 'https://') ? 'true' : 'false',
            'DB_CONNECTION' => $data['db_connection'],
            'DB_HOST' => $this->envValue($data['db_host'] ?? '127.0.0.1'),
            'DB_PORT' => (string) ($data['db_port'] ?? self::defaultPort($data['db_connection'] ?? null)),
            'DB_DATABASE' => $this->envValue($data['db_database']),
            'DB_USERNAME' => $this->envValue($data['db_username'] ?? ''),
            'DB_PASSWORD' => $this->envValue($data['db_password'] ?? ''),
        ];

        foreach ($replacements as $key => $value) {
            $pattern = '/^'.preg_quote($key, '/').'=.*/m';
            $env = preg_match($pattern, $env)
                ? preg_replace($pattern, "{$key}={$value}", $env)
                : rtrim($env)."\n{$key}={$value}\n";
        }

        $directory = dirname($path);
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException('The environment directory is not writable.');
        }

        $temporary = tempnam($directory, '.env-install-');
        if ($temporary === false || file_put_contents($temporary, $env, LOCK_EX) === false) {
            throw new RuntimeException('The environment file could not be written.');
        }

        chmod($temporary, 0600);
        if (! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('The environment file could not be replaced.');
        }

        config([
            'app.key' => $appKey,
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => $data['app_url'],
            'session.secure' => str_starts_with(strtolower($data['app_url']), 'https://'),
        ]);
    }

    public function markInstalled(): void
    {
        $path = $this->installedPath();
        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)
            || file_put_contents($path, now()->toIso8601String(), LOCK_EX) === false) {
            throw new RuntimeException('Installation could not be finalized.');
        }

        chmod($path, 0600);
    }

    public function installedPath(): string
    {
        return config('installer.installed_path', storage_path('installed'));
    }

    public function envPath(): string
    {
        return config('installer.env_path', base_path('.env'));
    }

    private function valueFromEnvironment(string $env, string $key): string
    {
        if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $env, $matches)) {
            return '';
        }

        return trim($matches[1], " \t\n\r\0\x0B\"");
    }

    private function envValue(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
