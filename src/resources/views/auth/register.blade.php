<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Регистрация</title>
    <link rel="stylesheet" href="{{ asset('css/bets.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .login-wrap { max-width: 480px; margin: 24px auto; }
        .form-row { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
        .form-row label { font-size: 14px; color: #374151; }
        .form-row input { padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; }
        .error { color: #b91c1c; font-size: 13px; }
    </style>
    </head>
<body>
    @include('partials.header')
    <main>
        <div class="container">
            <div class="card login-wrap">
                <h1 class="text-2xl font-bold">Регистрация</h1>
                <form method="post" action="{{ url('/register') }}">
                    @csrf
                    <div class="form-row">
                        <label for="username">Логин</label>
                        <input id="username" name="username" type="text" value="{{ old('username') }}" required autofocus>
                        @error('username')
                            <div class="error">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-row">
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required>
                        @error('email')
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
                    <div class="form-row">
                        <label for="password_confirmation">Подтверждение пароля</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required>
                    </div>
                    <div class="row" style="align-items:center; gap:8px;">
                        <button class="btn btn-primary" type="submit">Зарегистрироваться</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</body>
</html>