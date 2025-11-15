<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Создание пользователя</title>
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
<main class="container mx-auto p-6">
    <div class="row">
        <h1 class="text-2xl font-bold mb-6">Создать пользователя</h1>
    </div>
    <div class="card admin-create-user">
        <h2 class="px-4 pl-8">Форма создания</h2>
        <div class="p-4">
            <form method="post" action="{{ route('admin.users.store') }}">
                @csrf
                        <div class="row mb-4">
                            <div class="block" data-label="Логин">
                                <input placeholder="admin" name="username" class="border rounded-md px-3 py-2 focus:outline-none border-gray-300 focus:ring-2 focus:ring-blue-500" required minlength="3" maxlength="50" />
                            </div>
                            
                        </div>
                        <div class="row mb-4">
                            <div class="block" data-label="Имя">
                                    <input placeholder="name" name="name" class="border rounded-md px-3 py-2 focus:outline-none border-gray-300 focus:ring-2 focus:ring-blue-500" required minlength="3" maxlength="100" />
                                </div>
                            </div>
                        <div class="row mb-4">
                            <div class="block" data-label="Email (опционально)">
                                <input placeholder="email@example.com" name="email" class="border rounded-md px-3 py-2 focus:outline-none border-gray-300 focus:ring-2 focus:ring-blue-500" type="email" />
                            </div>
                        </div>

                        <div class="row mb-4">
                        <div class="block" data-label="Пароль">
                                <input name="password" class="border rounded-md px-3 py-2 focus:outline-none border-gray-300 focus:ring-2 focus:ring-blue-500" type="password" required minlength="6" />
                            </div>
                        </div>

                        <div class="row mb-4">
                            <div class="block" data-label="Роль">
                                <select name="role" class="input" required>
                                    <option value="user">user</option>
                                    <option value="moderator">moderator</option>
                                    <option value="admin">admin</option>
                                </select>
                            </div>
                            <div class="rt-cell" data-label="Действия">
                                <button type="submit" class="btn btn-primary">Создать</button>
                                <a href="{{ route('admin.index') }}" class="btn">Отмена</a>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</main>
</body>
</html>