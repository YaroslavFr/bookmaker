<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required'],
        ]);

        $remember = (bool) $request->boolean('remember');

        $login = $request->string('login')->toString();
        $field = str_contains($login, '@') ? 'email' : 'username';
        $credentials = [
            $field => $login,
            'password' => $request->string('password')->toString(),
        ];

        if (Auth::attempt($credentials, $remember)) {
            $request->session()->regenerate();
            $user = Auth::user();
            $role = method_exists($user, 'getAttribute') ? $user->getAttribute('role') : null;
            if (strtolower((string) $role) === 'admin') {
                return redirect()->intended(route('admin.index'));
            }
            return redirect()->intended(route('home'));
        }

        return back()->withErrors([
            'login' => 'Неверные учетные данные или пользователь не существует.',
        ])->onlyInput('login');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('home');
    }
}