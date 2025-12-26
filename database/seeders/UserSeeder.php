<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Role;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Fetch roles dynamically (must exist before running this seeder)
        $adminRole = Role::where('role', 'Admin')->first();
        $userRole = Role::where('role', 'User')->first();

        if (!$adminRole || !$userRole) {
            $this->command->warn('Roles not found. Please seed the roles table first.');
            Log::warning('UserSeeder aborted: roles missing.');
            return;
        }

        $users = [
            [
                'email'       => 'admin@cvinfotech.com',
                'name'        => 'Admin',
                'role_id'     => $adminRole->id,
                'password'    => 'Admin@123',
                'status'      => 1,
                'phone_no'    => '9999999999',
            ],
            [
                'email'        => 'user@cvinfotech.com',
                'name'         => 'User',
                'role_id'      => $userRole->id,
                'password'     => 'User@123',
                'status'       => 1,
                'phone_no'     => '8888888888',
                'affiliate_id' => 'User123'
            ],
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                array_merge(
                    $userData,
                    [
                        'email_verified_at' => Carbon::now(),
                        'password' => Hash::make($userData['password']),
                    ]
                )
            );
        }
    }
}
