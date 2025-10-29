<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $username = 'Admin-yar';
        $email = 'admin@example.com';
        $password = 'dev1234';

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $username,
                'username' => $username,
                'password' => $password,
            ]
        );

        // Если пользователь существует, но без username, обновим
        if (!$user->username) {
            $user->username = $username;
            $user->save();
        }
    }
}