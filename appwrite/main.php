<?php

/**
 * Appwrite Function entrypoint: runs the Laravel app (e-Mudra) inside an Appwrite PHP function.
 *
 * - HTTP executions are translated into Laravel requests (and the response translated back).
 * - Scheduled executions (cron "* * * * *") run `schedule:run` and drain the queue (webhooks, alerts).
 * - The code directory is read-only, so every writable path (storage, caches, compiled views) lives in /tmp.
 */

use Illuminate\Http\Request;

const EMUDRA_MIME = [
    'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8', 'mjs' => 'application/javascript; charset=utf-8',
    'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
    'ttf' => 'font/ttf', 'txt' => 'text/plain; charset=utf-8', 'map' => 'application/json', 'apk' => 'application/vnd.android.package-archive',
];

function emudra_prepare_environment(): void
{
    $tmp = '/tmp/emudra';
    foreach (['storage/framework/views', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/logs', 'bootstrap'] as $dir) {
        if (! is_dir("$tmp/$dir")) {
            @mkdir("$tmp/$dir", 0777, true);
        }
    }

    $defaults = [
        'APP_PACKAGES_CACHE' => "$tmp/bootstrap/packages.php",
        'APP_SERVICES_CACHE' => "$tmp/bootstrap/services.php",
        'APP_CONFIG_CACHE' => "$tmp/bootstrap/config.php",
        'APP_ROUTES_CACHE' => "$tmp/bootstrap/routes.php",
        'APP_EVENTS_CACHE' => "$tmp/bootstrap/events.php",
        'VIEW_COMPILED_PATH' => "$tmp/storage/framework/views",
        'LOG_CHANNEL' => 'stderr',
        'DB_PURE_PHP' => 'true',
    ];
    foreach ($defaults as $key => $value) {
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }
}

function emudra_boot()
{
    $root = dirname(__DIR__);
    require_once $root.'/vendor/autoload.php';

    /** @var \Illuminate\Foundation\Application $app */
    $app = require $root.'/bootstrap/app.php';
    $app->useStoragePath('/tmp/emudra/storage');
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    return $app;
}

function emudra_static(string $path, $res)
{
    $public = dirname(__DIR__).'/public';
    $real = realpath($public.$path);
    if ($path === '/' || $real === false || ! str_starts_with($real, $public.'/') || ! is_file($real) || str_ends_with($real, '.php')) {
        return null;
    }
    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    $immutable = str_starts_with($path, '/build/');

    return $res->binary((string) file_get_contents($real), 200, [
        'content-type' => EMUDRA_MIME[$ext] ?? 'application/octet-stream',
        'cache-control' => $immutable ? 'public, max-age=31536000, immutable' : 'public, max-age=3600',
    ]);
}

function emudra_request($req): Request
{
    $headers = array_change_key_case((array) $req->headers, CASE_LOWER);
    // Pin the public host to APP_URL (also blocks Host-header spoofing); fall back to the forwarded host.
    $host = parse_url((string) getenv('APP_URL'), PHP_URL_HOST) ?: ($headers['x-forwarded-host'] ?? $req->host ?? 'localhost');
    $path = $req->path ?: '/';
    $query = (string) ($req->queryString ?? '');
    $body = (string) ($req->bodyRaw ?? '');
    $method = strtoupper($req->method ?: 'GET');

    $server = [
        'REQUEST_METHOD' => $method,
        'REQUEST_URI' => $path.($query !== '' ? '?'.$query : ''),
        'QUERY_STRING' => $query,
        'SERVER_NAME' => $host,
        'SERVER_PORT' => 443,
        'HTTPS' => 'on',
        'REMOTE_ADDR' => trim(explode(',', $headers['x-appwrite-client-ip'] ?? $headers['x-forwarded-for'] ?? '127.0.0.1')[0]),
        'HTTP_HOST' => $host,
    ];
    foreach ($headers as $name => $value) {
        if (in_array($name, ['host', 'cookie', 'content-length'], true)) {
            continue;
        }
        $key = strtoupper(str_replace('-', '_', $name));
        $server[in_array($name, ['content-type'], true) ? 'CONTENT_TYPE' : 'HTTP_'.$key] = is_array($value) ? implode(', ', $value) : (string) $value;
    }
    $server['CONTENT_LENGTH'] = (string) strlen($body);

    $cookies = [];
    if (! empty($headers['cookie'])) {
        foreach (explode(';', $headers['cookie']) as $pair) {
            [$k, $v] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ($k !== '') {
                $cookies[urldecode($k)] = urldecode($v);
            }
        }
    }

    $parameters = [];
    if ($method !== 'GET' && str_contains(strtolower($server['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded')) {
        parse_str($body, $parameters);
    }
    $queryParams = [];
    parse_str($query, $queryParams);

    return Request::create(($server['HTTPS'] === 'on' ? 'https' : 'http').'://'.$host.$server['REQUEST_URI'], $method, $method === 'GET' ? $queryParams : $parameters, $cookies, [], $server, $body);
}

function emudra_drain_queue(int $seconds): void
{
    try {
        \Illuminate\Support\Facades\Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => $seconds, '--tries' => 3, '--sleep' => 0]);
    } catch (\Throwable $e) {
        error_log('queue drain failed: '.$e->getMessage());
    }
}

return function ($context) {
    $req = $context->req;
    $res = $context->res;

    try {
        emudra_prepare_environment();
        $path = $req->path ?: '/';
        $trigger = strtolower((string) (((array) $req->headers)['x-appwrite-trigger'] ?? 'http'));

        // Scheduled run (cron): Laravel scheduler + queue worker.
        if ($trigger === 'schedule') {
            $app = emudra_boot();
            \Illuminate\Support\Facades\Artisan::call('schedule:run');
            $scheduleOutput = \Illuminate\Support\Facades\Artisan::output();
            emudra_drain_queue(45);

            return $res->json(['ok' => true, 'schedule' => trim($scheduleOutput)]);
        }

        if ($static = emudra_static($path, $res)) {
            return $static;
        }

        $app = emudra_boot();
        $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
        $request = emudra_request($req);
        $response = $kernel->handle($request);

        // Only a single Set-Cookie header survives Appwrite's response layer: keep the session cookie, drop XSRF.
        $setCookie = null;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'XSRF-TOKEN') {
                continue;
            }
            if ($setCookie === null || $cookie->getName() === config('session.cookie')) {
                $setCookie = (string) $cookie;
            }
        }

        $headers = [];
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }
        unset($headers['transfer-encoding'], $headers['content-length']);
        if ($setCookie !== null) {
            $headers['set-cookie'] = $setCookie;
        }

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();
        $status = $response->getStatusCode();

        $kernel->terminate($request, $response);

        // Process webhooks/alerts queued by this request while the function is still alive.
        if ($request->method() !== 'GET' && $status < 500) {
            emudra_drain_queue(4);
        }

        return $res->binary($body, $status, $headers);
    } catch (\Throwable $e) {
        error_log($e::class.': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());

        return $res->text('Server error', 500);
    }
};
