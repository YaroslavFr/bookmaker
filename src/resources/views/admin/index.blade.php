<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Админ панель</title>
    <link rel="stylesheet" href="{{ asset('css/bets.css') }}">
    @if (file_exists(public_path('build/manifest.json')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
        <script src="{{ asset('js/app.js') }}" defer></script>
    @endif
    </head>
<body>
    @include('partials.header')
    <main>
    <div class="row">
        <h1 class="text-2xl font-bold mb-6">Админка</h1>
    </div>
    <div class="px-8 mb-4">
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary">Создать пользователя</a>
    </div>
    <div class="card admin-users-block">
        <h2 class="px-4 pl-8">Последние пользователи</h2>
        <div class="responsive-table rt rt--users">
            <div class="rt-head">
                <div class="rt-th">ID</div>
                <div class="rt-th">Логин</div>
                <div class="rt-th">Имя</div>
                <div class="rt-th">Email</div>
                <div class="rt-th">Роль</div>
            </div>
            <div class="rt-body">
                @foreach(($users ?? []) as $u)
                <div class="rt-row">
                    <div class="" data-label="ID">{{ $u->id }}</div>
                    <div class="" data-label="Логин">{{ $u->username }}</div>
                    <div class="" data-label="Имя">{{ $u->name }}</div>
                    <div class="" data-label="Email">{{ $u->email }}</div>
                    <div class="" data-label="Роль">{{ $u->role ?? 'user' }}</div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</main>
</body>
</html>