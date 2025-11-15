<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class ModeratorUserSeeder extends Seeder
{
    public function run(): void
    {
        $username = 'drujishe_bro';
        $email = 'drujishe_bro@example.com';
        $password = '12345qqq';

        $user = User::where('email', $email)
            ->orWhere('username', $username)
            ->first();

        if ($user) {
            $user->update([
                'name' => $username,
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'role' => 'moderator',
            ]);
            $this->command->info("Модератор обновлён: {$email}");
        } else {
            User::create([
                'name' => $username,
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'role' => 'moderator',
            ]);
            $this->command->info("Модератор создан: {$email}");
        }
    }
}