<?php

namespace Tests\Feature;

use App\Http\Middleware\RedirectIfInstalled;
use App\Models\User;
use App\Support\InstallationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class InstallationWizardTest extends TestCase
{
    use RefreshDatabase;

    private function beginInstallation(): void
    {
        @unlink(config('installer.installed_path'));
    }

    public function test_first_access_redirects_to_installer_when_not_configured(): void
    {
        $this->beginInstallation();

        $this->get('/')->assertRedirect(route('install.index'));
        $this->get(route('install.index'))
            ->assertOk()
            ->assertSee('Server Requirements')
            ->assertSee('PDO MySQL (required)')
            ->assertDontSee('PDO SQLite');
        $this->get(route('install.database'))->assertOk()
            ->assertSee('MySQL is required')
            ->assertDontSee('SQLite');
    }

    public function test_uninstalled_api_returns_a_generic_service_unavailable_response(): void
    {
        $this->beginInstallation();

        $this->getJson('/api/v1/accounts')
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Application installation is required.']);
    }

    public function test_successful_installation_creates_admin_and_disables_installer(): void
    {
        $this->beginInstallation();

        $this->get(route('install.admin'))->assertOk();
        $this->post(route('install.admin.save'), [
            'name' => 'Initial Admin',
            'email' => 'owner@example.com',
            'password' => 'securepass10',
            'password_confirmation' => 'securepass10',
        ])->assertRedirect(route('install.done'));

        $admin = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('securepass10', $admin->password));
        $this->assertFileExists(config('installer.installed_path'));

        $this->get(route('install.done'))
            ->assertOk()
            ->assertSee('Installation Complete')
            ->assertSee('Go to Login');

        $this->get(route('install.index'))->assertRedirect(route('home'));
        $this->get(route('install.done'))->assertRedirect(route('home'));
    }

    public function test_invalid_database_credentials_are_rejected_without_exposure(): void
    {
        $secret = 'do-not-expose-this-password';
        $installer = Mockery::mock(InstallationManager::class)->makePartial();
        $installer->shouldReceive('testDatabaseConnection')
            ->once()
            ->andThrow(new RuntimeException("Connection failed using {$secret}"));
        $this->app->instance(InstallationManager::class, $installer);
        Log::spy();

        $response = $this
            ->withoutMiddleware(RedirectIfInstalled::class)
            ->from(route('install.database'))
            ->post(route('install.database.save'), [
                'db_connection' => 'mysql',
                'db_host' => 'db.internal',
                'db_port' => 3306,
                'db_database' => 'gateway',
                'db_username' => 'gateway',
                'db_password' => $secret,
                'app_url' => 'https://gateway.example',
                'app_name' => 'Gateway',
            ]);

        $response->assertRedirect(route('install.database'))
            ->assertSessionHasErrors('db_host')
            ->assertDontSee($secret)
            ->assertSessionMissing('_old_input.db_password');
        Log::shouldNotHaveReceived('error');
    }

    public function test_environment_is_written_atomically_with_a_generated_key(): void
    {
        $envPath = tempnam(sys_get_temp_dir(), 'gateway-env-');
        $originalEnvironment = config('app.env');
        $originalKey = config('app.key');
        $originalDebug = config('app.debug');
        $originalUrl = config('app.url');
        $originalSecureCookie = config('session.secure');
        config([
            'installer.env_path' => $envPath,
        ]);

        try {
            app(InstallationManager::class)->writeEnvironment([
                'db_connection' => 'mysql',
                'db_host' => '127.0.0.1',
                'db_port' => 3306,
                'db_database' => 'gateway',
                'db_username' => 'gateway',
                'db_password' => '',
                'app_url' => 'https://gateway.example',
                'app_name' => 'Secure Gateway',
            ]);

            $env = file_get_contents($envPath);
            $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]+$/m', $env);
            $this->assertStringContainsString('APP_ENV=production', $env);
            $this->assertStringContainsString('APP_DEBUG=false', $env);
            $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $env);
            $this->assertSame(0600, fileperms($envPath) & 0777);
        } finally {
            config([
                'app.env' => $originalEnvironment,
                'app.key' => $originalKey,
                'app.debug' => $originalDebug,
                'app.url' => $originalUrl,
                'session.secure' => $originalSecureCookie,
            ]);
            @unlink($envPath);
        }
    }

    public function test_sqlite_is_rejected_by_installer(): void
    {
        $this->withoutMiddleware(RedirectIfInstalled::class)
            ->from(route('install.database'))
            ->post(route('install.database.save'), [
                'db_connection' => 'sqlite',
                'app_url' => 'https://gateway.example',
                'app_name' => 'Gateway',
            ])
            ->assertRedirect(route('install.database'))
            ->assertSessionHasErrors('db_connection');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only MySQL is supported');
        app(InstallationManager::class)->configureDatabase(['db_connection' => 'sqlite']);
    }

    public function test_migration_failure_is_safe_and_can_be_retried(): void
    {
        Log::spy();
        Artisan::shouldReceive('call')->once()->andThrow(new RuntimeException(
            'SQLSTATE failed for db.internal using very-secret-password'
        ));

        $this->withoutMiddleware(RedirectIfInstalled::class)
            ->postJson(route('install.migrate.run'))
            ->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => 'Database migration failed. Check the application logs for details.',
            ])
            ->assertDontSee('db.internal')
            ->assertDontSee('very-secret-password');

        $this->assertFalse(config('installer.completed', false));
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) {
            $logged = $message.json_encode($context);

            return ! str_contains($logged, 'db.internal')
                && ! str_contains($logged, 'very-secret-password');
        });
    }

    public function test_existing_marker_blocks_duplicate_installation(): void
    {
        $this->get(route('install.index'))->assertRedirect(route('home'));
        $this->post(route('install.admin.save'), [
            'name' => 'Attacker',
            'email' => 'attacker@example.com',
            'password' => 'attackerpass10',
            'password_confirmation' => 'attackerpass10',
        ])->assertRedirect(route('home'));

        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.com']);
    }

    public function test_existing_admin_blocks_reinstall_when_marker_is_deleted(): void
    {
        $this->beginInstallation();
        User::create([
            'name' => 'Existing Admin',
            'email' => 'existing@example.com',
            'password' => 'securepass10',
            'role' => 'admin',
        ]);

        $this->get(route('install.index'))->assertRedirect(route('home'));
        $this->get('/')->assertOk();
    }

    public function test_incomplete_installation_can_resume_after_migrations(): void
    {
        $this->beginInstallation();

        $this->get(route('install.admin'))
            ->assertOk()
            ->assertSee('Create Admin Account');

        $this->get('/dashboard')->assertRedirect(route('install.index'));
    }

    public function test_incomplete_migrations_prevent_admin_creation(): void
    {
        $this->beginInstallation();

        // Delete the last migration record to simulate an interrupted or partial migration
        $lastMigration = DB::table('migrations')->orderByDesc('id')->first();
        $this->assertNotNull($lastMigration);
        DB::table('migrations')->where('id', $lastMigration->id)->delete();

        $this->assertFalse(app(InstallationManager::class)->databaseIsMigrated());

        $this->get(route('install.admin'))
            ->assertRedirect(route('install.migrate'))
            ->assertSessionHasErrors('database');

        $this->post(route('install.admin.save'), [
            'name' => 'Sneaky Admin',
            'email' => 'sneaky@example.com',
            'password' => 'securepass10',
            'password_confirmation' => 'securepass10',
        ])
            ->assertRedirect(route('install.migrate'))
            ->assertSessionHasErrors('database');

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }

    public function test_missing_required_table_prevents_admin_creation(): void
    {
        $this->beginInstallation();

        Schema::dropIfExists('installation_guards');
        $this->assertFalse(app(InstallationManager::class)->databaseIsMigrated());

        $this->get(route('install.admin'))
            ->assertRedirect(route('install.migrate'))
            ->assertSessionHasErrors('database');
    }

    public function test_sequential_admin_creation_cannot_duplicate_or_replace_existing_admin(): void
    {
        $this->beginInstallation();

        // Step 1: Create initial admin
        $this->post(route('install.admin.save'), [
            'name' => 'First Admin',
            'email' => 'admin1@example.com',
            'password' => 'securepass10',
            'password_confirmation' => 'securepass10',
        ])->assertRedirect(route('install.done'));

        $this->assertDatabaseHas('users', ['email' => 'admin1@example.com', 'role' => 'admin']);
        $this->assertDatabaseHas('installation_guards', ['name' => 'primary_admin']);

        // Step 2: Attempting to create a second admin sequential request when installed file is removed
        $this->beginInstallation();

        $this->withoutMiddleware(RedirectIfInstalled::class)
            ->post(route('install.admin.save'), [
                'name' => 'Second Admin',
                'email' => 'admin2@example.com',
                'password' => 'securepass10',
                'password_confirmation' => 'securepass10',
            ])
            ->assertStatus(409);

        $this->assertDatabaseMissing('users', ['email' => 'admin2@example.com']);
        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    public function test_concurrent_admin_creation_is_serialized_and_rejected_by_database_guard(): void
    {
        $this->beginInstallation();

        // Simulate another concurrent process acquiring the database guard first
        DB::table('installation_guards')->insert([
            'name' => 'primary_admin',
            'created_at' => now(),
        ]);

        $this->withoutMiddleware(RedirectIfInstalled::class)
            ->post(route('install.admin.save'), [
                'name' => 'Racing Admin',
                'email' => 'racing@example.com',
                'password' => 'securepass10',
                'password_confirmation' => 'securepass10',
            ])
            ->assertStatus(409);

        $this->assertDatabaseMissing('users', ['email' => 'racing@example.com']);
        $this->assertSame(0, User::where('role', 'admin')->count());
    }
}
