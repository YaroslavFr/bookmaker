<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminOnly
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Require authentication first
        if (!Auth::check()) {
            // Redirect to login preserving intended URL
            return redirect()->route('login');
        }

        $user = Auth::user();
        $adminEmail = env('ADMIN_EMAIL');
        $adminUsername = env('ADMIN_USERNAME');

        $isAdmin = false;
        if ($adminEmail && $user->email === $adminEmail) {
            $isAdmin = true;
        }
        if (!$isAdmin && $adminUsername && method_exists($user, 'getAttribute')) {
            $username = $user->getAttribute('username');
            if ($username && $username === $adminUsername) {
                $isAdmin = true;
            }
        }

        if (!$isAdmin) {
            abort(403, 'Доступ только для администратора');
        }

        return $next($request);
    }
}