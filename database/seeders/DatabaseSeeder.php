<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::query()->firstOrCreate(
            ['email' => 'georgie.reis@outlook.com'],
            [
                'name' => 'Georgie Reis',
                'password' => Hash::make('Enghaw1986**'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        $this->call([
            SystemSettingSeeder::class,
        ]);
    }
}
