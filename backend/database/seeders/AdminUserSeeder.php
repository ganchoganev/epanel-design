<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('admin.email');
        $password = config('admin.password');
        $name = (string) config('admin.name');

        if (! is_string($password) || $password === '') {
            $this->command?->warn('ADMIN_PASSWORD не е зададен в .env — администратор не се създава.');

            return;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = $name;
        $user->password = $password;
        $user->save();
    }
}
