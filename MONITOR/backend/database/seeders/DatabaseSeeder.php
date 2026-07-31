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
        $this->call([
            SchemaProfileSeeder::class,
            SiteConnectionSeeder::class,
        ]);

        $executive = Role::updateOrCreate(
            ['role_name' => 'Executive'],
            [
                'description' => 'Full read access to every dashboard and every source.',
                'permissions' => ['overview', 'operations', 'revenue', 'financials', 'consolidated'],
            ]
        );

        Role::updateOrCreate(
            ['role_name' => 'Viewer'],
            [
                'description' => 'Headline dashboards only, no profit and loss detail.',
                'permissions' => ['overview', 'consolidated'],
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
