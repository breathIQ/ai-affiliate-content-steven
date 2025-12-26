<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         $roles = [
            [
                'role'    => 'Admin',
                'status'       => 1,
            ],
            [
                'role'    => 'User',
                'status'       => 1,
            ],
        ];

        foreach ($roles as $roleData) {
            Role::updateOrCreate(
                ['role' => $roleData['role']],
                $roleData
            );
        }
    }
}
