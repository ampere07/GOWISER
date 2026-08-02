<?php

namespace Database\Seeders;

use App\Models\ColorPalette;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
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

        // The five shipped roles, from App\Support\Permissions::ROLE_PRESETS —
        // Super Admin, Executive, Finance Admin, Operations Lead, Tech Lead and
        // Auditor. Defined there rather than here so the middleware, the
        // permission-mapping screen and this seeder cannot drift apart about what
        // a role means.
        //
        // updateOrCreate on the *description and permissions* would overwrite a
        // deployment's own tuning on every deploy, so an existing role keeps its
        // permission map and only a missing one is created. Reshaping a role is
        // what the User Management screen is for.
        $roles = [];

        foreach (Permissions::ROLE_PRESETS as $name => $preset) {
            $existing = Role::where('role_name', $name)->first();

            if ($existing) {
                $roles[$name] = $existing;

                continue;
            }

            $roles[$name] = Role::create([
                'role_name' => $name,
                'description' => $preset['description'],
                'permissions' => Permissions::preset($name),
                'is_system' => true,
            ]);
        }

        $superAdmin = $roles['Super Admin'];

        // Databases carries credentials for every monitored source, so it is
        // granted only to Super Admin and only here — no preset hands it out.
        $superAdmin->permissions = array_values(array_unique(array_merge(
            is_array($superAdmin->permissions) ? $superAdmin->permissions : [],
            [Permissions::MODULE_DATABASES]
        )));
        $superAdmin->save();

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
                'role_id' => $superAdmin->id,
                'active' => true,
            ]
        );

        $this->command->info('Seeded ' . count($roles) . ' roles, the palette and the admin user.');
    }
}
