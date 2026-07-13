<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;


class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Creates a default local admin testing account
        User::create([
            'name' => 'John Seller',
            'email' => 'john@ewaste.com',
            'password' => Hash::make('password123'),
        ]);

        User::create([
            'name' => 'Jane Buyer',
            'email' => 'jane@ewaste.com',
            'password' => Hash::make('password123'),
        ]);        //
    }
}
