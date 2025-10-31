<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create 
                            {--username= : Username for the admin user}
                            {--email= : Email address for the admin user}
                            {--password= : Password for the admin user}
                            {--force : Force update if user already exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or update admin user safely';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $username = $this->option('username') ?: $this->ask('Username', 'admin');
        $email = $this->option('email') ?: $this->ask('Email address');
        $password = $this->option('password') ?: $this->secret('Password');

        // Валидация данных
        $validator = Validator::make([
            'username' => $username,
            'email' => $email,
            'password' => $password,
        ], [
            'username' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            $this->error('Validation failed:');
            foreach ($validator->errors()->all() as $error) {
                $this->error("  - {$error}");
            }
            return 1;
        }

        // Проверяем существующего пользователя
        $existingUser = User::where('email', $email)
            ->orWhere('username', $username)
            ->first();

        if ($existingUser && !$this->option('force')) {
            $this->warn("User with email '{$email}' or username '{$username}' already exists.");
            if (!$this->confirm('Do you want to update this user?')) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        try {
            if ($existingUser) {
                // Обновляем существующего пользователя
                $existingUser->update([
                    'name' => $username,
                    'username' => $username,
                    'email' => $email,
                    'password' => Hash::make($password),
                ]);
                $this->info("Admin user updated successfully!");
                $this->table(['Field', 'Value'], [
                    ['ID', $existingUser->id],
                    ['Username', $existingUser->username],
                    ['Email', $existingUser->email],
                    ['Updated', $existingUser->updated_at->format('Y-m-d H:i:s')],
                ]);
            } else {
                // Создаём нового пользователя
                $user = User::create([
                    'name' => $username,
                    'username' => $username,
                    'email' => $email,
                    'password' => Hash::make($password),
                ]);
                $this->info("Admin user created successfully!");
                $this->table(['Field', 'Value'], [
                    ['ID', $user->id],
                    ['Username', $user->username],
                    ['Email', $user->email],
                    ['Created', $user->created_at->format('Y-m-d H:i:s')],
                ]);
            }

            $this->warn('IMPORTANT: Make sure to keep your admin credentials secure!');
            return 0;

        } catch (\Exception $e) {
            $this->error("Failed to create/update admin user: {$e->getMessage()}");
            return 1;
        }
    }
}