<?php

namespace Database\Seeders;

use App\Models\ColorPalette;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // Monitored databases are not seeded: they carry credentials, and those
        // are entered on the Databases page where they are encrypted at rest.
        $this->call([
            SchemaProfileSeeder::class,
        ]);

        $executive = Role::updateOrCreate(
            ['role_name' => 'Executive'],
            [
                'description' => 'Full read access to every dashboard and every source.',
                'permissions' => [
                    // The five operational sections.
                    'subscriber-analytics', 'financial', 'field-operations', 'tech', 'employee',
                    // The executive rollups.
                    'overview', 'operations', 'revenue', 'financials', 'consolidated',
                    // Manage which databases the portal reads. Granted here
                    // because this role is the administrator; it is the only
                    // permission in the app that allows a write, and it exposes
                    // credentials for every monitored database.
                    'databases',
                ],
            ]
        );

        Role::updateOrCreate(
            ['role_name' => 'Viewer'],
            [
                'description' => 'Headline dashboards only, no profit and loss or staff detail.',
                // Deliberately excludes 'financial' and 'employee': the first
                // exposes expense lines and payee names, the second attributes
                // collections to named staff.
                'permissions' => [
                    'subscriber-analytics', 'field-operations', 'tech',
                    'overview', 'consolidated',
                ],
            ]
        );

        ColorPalette::updateOrCreate(
            ['palette_name' => 'Default'],
            [
                'primary' => '#7c3aed',
                'secondary' => '#6d28d9',
                'accent' => '#a78bfa',
                'status' => 'active',
                'updated_by' => 'seeder',
            ]
        );

        // Credentials come from .env so a real password never lands in git.
        // Set SEED_ADMIN_PASSWORD before running db:seed.
        $password = env('SEED_ADMIN_PASSWORD');

        if (!$password) {
            $this->command->warn('SEED_ADMIN_PASSWORD is not set — skipping the admin user.');
            $this->command->warn('Set it in .env, then re-run: php artisan db:seed');

            return;
        }

        User::updateOrCreate(
            ['username' => env('SEED_ADMIN_USERNAME', 'admin')],
            [
                'email_address' => env('SEED_ADMIN_EMAIL', 'admin@example.com'),
                // Assigned raw: User::setPasswordHashAttribute() hashes it.
                'password_hash' => $password,
                'first_name' => 'Monitor',
                'last_name' => 'Administrator',
                'role_id' => $executive->id,
                'active' => true,
            ]
        );

        $this->command->info('Seeded roles, palette and the admin user.');
    }
}
