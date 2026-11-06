<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RedirectIfInstalled
{
    public function handle(Request $request, Closure $next)
    {
        if (file_exists(storage_path('installed'))) {
            return redirect()->route('home');
        }

        return $next($request);
    }
}
