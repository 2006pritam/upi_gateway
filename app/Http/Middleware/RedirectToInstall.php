<?php

namespace App\Http\Middleware;

use App\Support\InstallationManager;
use Closure;
use Illuminate\Http\Request;

class RedirectToInstall
{
    public function __construct(private InstallationManager $installer) {}

    public function handle(Request $request, Closure $next)
    {
        $installerRequest = str_starts_with($request->path(), 'install');

        // A clean installation has no `sessions` table yet. Installer pages
        // therefore use file sessions until migrations and admin creation finish.
        if ($installerRequest) {
            $originalDriver = config('session.driver');
            config(['session.driver' => 'file']);

            try {
                return $next($request);
            } finally {
                config(['session.driver' => $originalDriver]);
            }
        }

        if (! $this->installer->isInstalled()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Application installation is required.'], 503);
            }

            return redirect()->route('install.index');
        }

        return $next($request);
    }
}
