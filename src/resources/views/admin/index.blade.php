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
        <div class="container">
    <div class="mt-4 row">
        <h1 class="text-2xl font-bold">Админка</h1>
    </div>
    <div class="mt-4 mb-4">
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary">Создать пользователя</a>
    </div>
    <div class="card admin-users-block">
        <h2 class="m-0 border-b-1 border-blue-100 pb-2">Последние пользователи</h2>
        <div class="responsive-table rt rt--users">
            <div class="grid grid-cols-9 p-1 pl-0 border-b-1 border-blue-100">
                <div class="rt-th">ID</div>
                <div class="rt-th">Логин</div>
                <div class="rt-th">Имя</div>
                <div class="rt-th">Email</div>
                <div class="rt-th">Роль</div>
                <div class="rt-th">Баланс</div>
                <div class="rt-th">Пароль</div>
                <div class="rt-th">Действия</div>
            </div>
            <div class="">
                @foreach(($users ?? []) as $u)
                <form class="grid grid-cols-9 border-b-1 border-blue-100 items-center" method="post" action="{{ route('admin.users.update', ['user' => $u->id]) }}">
                    @csrf
                    <div class="" data-label="ID">{{ $u->id }}</div>
                    <div class="" data-label="Логин">{{ $u->username }}</div>
                    <div class="" data-label="Имя">{{ $u->name }}</div>
                    <div class="" data-label="Email">
                        <input name="email" type="email" value="{{ $u->email }}" class="border rounded px-2 py-1 w-full" />
                    </div>
                    <div class="" data-label="Роль">{{ $u->role ?? 'user' }}</div>
                    <div class="" data-label="Баланс">
                        <input name="balance" type="number" step="0.01" min="0" value="{{ (float) ($u->balance ?? 0) }}" class="border rounded px-2 py-1 w-28" />
                    </div>
                    <div class="" data-label="Пароль">
                        <input name="password" type="password" placeholder="Новый пароль" class="border rounded px-2 py-1 w-full" />
                    </div>
                    <div class="" data-label="Действия">
                        <button type="submit" class="btn btn-primary btn-sm">Сохранить</button>
                    </div>
                </form>
                @endforeach
            </div>
        </div>
    </div>
    </div>
</main>
</body>
</html>