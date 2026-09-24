<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->is_superadmin) {
            abort(403);
        }
        return $next($request);
    }
}
