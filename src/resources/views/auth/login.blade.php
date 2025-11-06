<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход</title>
    <link rel="stylesheet" href="{{ asset('css/bets.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .login-wrap { max-width: 420px; margin: 24px auto; }
        .form-row { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
        .form-row label { font-size: 14px; color: #374151; }
        /* Добавляем text, чтобы логин имел те же стили, что и пароль */
        .form-row input[type="email"], .form-row input[type="password"], .form-row input[type="text"] { padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; }
        .error { color: #b91c1c; font-size: 13px; }
    </style>
    </head>
<body>
    @include('partials.header')
    <main>
        <div class="container">
            <div class="card login-wrap">
                <h1 class="text-2xl font-bold">Авторизация</h1>
                <form method="post" action="{{ url('/login') }}">
                    @csrf
                    <div class="form-row">
                        <label for="login">Логин или Email</label>
                        <input id="login" name="login" type="text" value="{{ old('login') }}" required autofocus>
                        @error('login')
                            <div class="error">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-row">
                        <label for="password">Пароль</label>
                        <input id="password" name="password" type="password" required>
                        @error('password')
                            <div class="error">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="row" style="align-items:center; gap:8px;">
                        <label class="row" style="gap:6px;">
                            <input type="checkbox" name="remember" value="1">
                            Запомнить меня
                        </label>
                        <button class="btn btn-primary" type="submit">Войти</button>
                    </div>
                    <div class="row" style="margin-top:8px;">
                        <a href="{{ route('password.request') }}" class="text-blue-600 hover:underline">Забыли пароль?</a>
                    </div>
                </form>
            </div>
        </div>
    </main>
</body>
</html>