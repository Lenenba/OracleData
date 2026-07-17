<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Seed stable demo users for local development.
     */
    public function run(): void
    {
        $users = [
            [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'is_super_admin' => true,
            ],
            [
                'name' => 'Analyste Oracle',
                'email' => 'analyste@oracledata.test',
            ],
            [
                'name' => 'Contrôle Finance',
                'email' => 'finance@oracledata.test',
            ],
        ];

        foreach ($users as $user) {
            $model = User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => 'password',
                    'email_verified_at' => now(),
                ],
            );

            $model->forceFill([
                'is_super_admin' => (bool) ($user['is_super_admin'] ?? false),
            ])->save();
        }
    }
}
