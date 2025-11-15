<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminPanelOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }
        $user = Auth::user();
        $role = method_exists($user, 'getAttribute') ? $user->getAttribute('role') : null;
        if (strtolower((string) $role) !== 'admin') {
            abort(403);
        }
        return $next($request);
    }
}