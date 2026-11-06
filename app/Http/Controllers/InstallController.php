<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class InstallController extends Controller
{
    public function index()
    {
        return view('install.index', ['requirements' => $this->checkRequirements()]);
    }

    public function database()
    {
        return view('install.database');
    }

    public function databaseSave(Request $request)
    {
        $data = $request->validate([
            'db_connection' => 'required|in:sqlite,mysql',
            'db_host'       => 'required_if:db_connection,mysql',
            'db_port'       => 'required_if:db_connection,mysql',
            'db_database'   => 'required',
            'db_username'   => 'required_if:db_connection,mysql',
            'db_password'   => 'nullable',
            'app_url'       => 'required|url',
            'app_name'      => 'required|string|max:100',
        ]);

        // Test connection
        try {
            config(['database.default' => $data['db_connection']]);
            if ($data['db_connection'] === 'mysql') {
                config([
                    'database.connections.mysql.host'     => $data['db_host'],
                    'database.connections.mysql.port'     => $data['db_port'],
                    'database.connections.mysql.database' => $data['db_database'],
                    'database.connections.mysql.username' => $data['db_username'],
                    'database.connections.mysql.password' => $data['db_password'] ?? '',
                ]);
            }
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            return back()->withErrors(['db_host' => 'Could not connect: ' . $e->getMessage()])->withInput();
        }

        // Write .env
        $this->writeEnv($data);

        // Generate app key if not set
        if (empty(config('app.key'))) {
            Artisan::call('key:generate', ['--force' => true]);
        }

        return redirect()->route('install.migrate');
    }

    public function migrate()
    {
        return view('install.migrate');
    }

    public function migrateRun()
    {
        try {
            if (config('database.default') === 'sqlite') {
                $path = database_path('database.sqlite');
                if (! file_exists($path)) {
                    touch($path);
                }
            }
            Artisan::call('migrate', ['--force' => true]);
            $output = Artisan::output();
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }

        return response()->json(['success' => true, 'output' => $output]);
    }

    public function admin()
    {
        return view('install.admin');
    }

    public function adminSave(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',
        ]);

        \App\Models\User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => 'admin',
            'is_active' => true,
        ]);

        // Mark as installed
        file_put_contents(storage_path('installed'), now()->toDateTimeString());

        Artisan::call('config:clear');
        Artisan::call('cache:clear');

        return redirect()->route('install.done');
    }

    public function done()
    {
        return view('install.done');
    }

    private function checkRequirements(): array
    {
        return [
            ['name' => 'PHP >= 8.2',         'pass' => version_compare(PHP_VERSION, '8.2.0', '>=')],
            ['name' => 'PDO Extension',       'pass' => extension_loaded('pdo')],
            ['name' => 'PDO SQLite',          'pass' => extension_loaded('pdo_sqlite')],
            ['name' => 'PDO MySQL',           'pass' => extension_loaded('pdo_mysql')],
            ['name' => 'OpenSSL Extension',   'pass' => extension_loaded('openssl')],
            ['name' => 'Mbstring Extension',  'pass' => extension_loaded('mbstring')],
            ['name' => 'Tokenizer Extension', 'pass' => extension_loaded('tokenizer')],
            ['name' => 'JSON Extension',      'pass' => extension_loaded('json')],
            ['name' => 'cURL Extension',      'pass' => extension_loaded('curl')],
            ['name' => 'storage/ writable',   'pass' => is_writable(storage_path())],
            ['name' => '.env writable',       'pass' => is_writable(base_path('.env')) || ! file_exists(base_path('.env'))],
        ];
    }

    private function writeEnv(array $data): void
    {
        $envPath = base_path('.env');
        $env = file_exists($envPath) ? file_get_contents($envPath) : file_get_contents(base_path('.env.example'));

        $replacements = [
            'APP_NAME'      => '"' . $data['app_name'] . '"',
            'APP_URL'       => $data['app_url'],
            'DB_CONNECTION' => $data['db_connection'],
            'DB_HOST'       => $data['db_host'] ?? '127.0.0.1',
            'DB_PORT'       => $data['db_port'] ?? '3306',
            'DB_DATABASE'   => $data['db_connection'] === 'sqlite' ? 'database/database.sqlite' : $data['db_database'],
            'DB_USERNAME'   => $data['db_username'] ?? '',
            'DB_PASSWORD'   => $data['db_password'] ?? '',
        ];

        foreach ($replacements as $key => $value) {
            if (preg_match("/^{$key}=.*/m", $env)) {
                $env = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $env);
            } else {
                $env .= "\n{$key}={$value}";
            }
        }

        file_put_contents($envPath, $env);
    }
}
