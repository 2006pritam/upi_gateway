<?php

namespace App\Http\Controllers;

class IntegrationDownloadController extends Controller
{
    private const FILES = [
        'woocommerce' => ['woocommerce/emudra-gateway.php', 'emudra-gateway.php'],
        'php' => ['php/EMudra.php', 'EMudra.php'],
        'node' => ['node/emudra.js', 'emudra.js'],
    ];

    /** Serves the helper with this gateway's own URL filled in, so merchants only paste their keys. */
    public function show(string $kind)
    {
        [$path, $name] = self::FILES[$kind];
        $code = str_replace('__GATEWAY_URL__', rtrim(config('app.url'), '/'), file_get_contents(base_path('integrations/'.$path)));

        return response($code, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
