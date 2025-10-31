<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Получаем данные из переменных окружения
        $username = env('ADMIN_USERNAME', 'admin');
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $password = env('ADMIN_PASSWORD', 'password');

        // Проверяем, что критически важные данные заданы
        if (!$email || !$password) {
            $this->command->warn('ADMIN_EMAIL и ADMIN_PASSWORD должны быть заданы в .env файле');
            return;
        }

        // Ищем существующего админа по email или username
        $user = User::where('email', $email)
            ->orWhere('username', $username)
            ->orWhere('email', 'admin@example.com') // старый email для миграции
            ->first();

        if ($user) {
            // Обновляем существующего пользователя
            $user->update([
                'name' => $username,
                'username' => $username,
                'email' => $email,
                'password' => $password,
            ]);
            $this->command->info("Администратор обновлён: {$email}");
        } else {
            // Создаём нового администратора
            User::create([
                'name' => $username,
                'username' => $username,
                'email' => $email,
                'password' => $password,
            ]);
            $this->command->info("Администратор создан: {$email}");
        }
    }
}