<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RedirectToInstall
{
    public function handle(Request $request, Closure $next)
    {
        if (! file_exists(storage_path('installed')) && ! str_starts_with($request->path(), 'install')) {
            return redirect()->route('install.index');
        }

        return $next($request);
    }
}
