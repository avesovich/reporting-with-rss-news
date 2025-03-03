<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Ensure roles exist
        $this->createRoles();

        // Create users and assign roles
        $admin = User::create([
            'name' => 'Administrator',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
        ]);
        $admin->assignRole('administrator');

        $editor = User::create([
            'name' => 'Editor 1',
            'email' => 'editor1@example.com',
            'password' => Hash::make('password123'),
        ]);
        $editor->assignRole('editor');

        $executive = User::create([
            'name' => 'Executive 1',
            'email' => 'executive1@example.com',
            'password' => Hash::make('password123'),
        ]);
        $executive->assignRole('executive');
    }

    /**
     * Create necessary roles if they don't exist.
     */
    private function createRoles()
    {
        $roles = ['administrator', 'editor', 'executive'];

        foreach ($roles as $role) {
            if (!Role::where('name', $role)->exists()) {
                Role::create(['name' => $role]);
            }
        }
    }
}
