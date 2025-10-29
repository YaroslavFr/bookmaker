<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Восстановление пароля</title>
    <link rel="stylesheet" href="{{ asset('css/bets.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .login-wrap { max-width: 480px; margin: 24px auto; }
        .form-row { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
        .form-row label { font-size: 14px; color: #374151; }
        .form-row input { padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; }
        .error { color: #b91c1c; font-size: 13px; }
        .status { color: #059669; font-size: 13px; }
    </style>
    </head>
<body>
    <header>
        <div class="container">
            <div class="logo">SPORT-KUCKOLD</div>
            <div class="description">Восстановление пароля</div>
            @include('partials.nav')
        </div>
    </header>
    <main>
        <div class="container">
            <div class="card login-wrap">
                <h1 class="text-2xl font-bold">Восстановление пароля</h1>
                @if (session('status'))
                    <div class="status">{{ session('status') }}</div>
                @endif
                <form method="post" action="{{ route('password.email') }}">
                    @csrf
                    <div class="form-row">
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
                        @error('email')
                            <div class="error">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="row" style="align-items:center; gap:8px;">
                        <button class="btn btn-primary" type="submit">Отправить ссылку для сброса</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</body>
</html>