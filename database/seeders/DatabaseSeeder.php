<?php

declare(strict_types=1);

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin',
            'email' => 'mishaa.pro@proton.me',
            'two_factor_recovery_codes' => '111111',
            'email_verified_at' => now(),
            'password' => Hash::make('mishaa.pro@proton.me'),
        ]);
    }
}
