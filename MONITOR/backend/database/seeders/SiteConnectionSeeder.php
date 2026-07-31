<?php

namespace Database\Seeders;

use App\Models\SiteConnection;
use Illuminate\Database\Seeder;

/**
 * Local development sites, so a fresh checkout has something to look at.
 *
 * Production sites are added through the admin screen, not here — credentials
 * do not belong in the repository. These rows only appear when the matching
 * SEED_SITE_* variables are present.
 */
class SiteConnectionSeeder extends Seeder
{
    public function run()
    {
        $sites = [
            [
                'key' => 'sync-local',
                'label' => env('SEED_SITE_SYNC_LABEL', 'SYNC (local)'),
                'profile_key' => 'sync',
                'database' => env('SEED_SITE_SYNC_DATABASE'),
                'username' => env('SEED_SITE_SYNC_USERNAME'),
                'password' => env('SEED_SITE_SYNC_PASSWORD'),
                'sort_order' => 1,
            ],
            [
                'key' => 'netmanager-local',
                'label' => env('SEED_SITE_NETMANAGER_LABEL', 'NetManager (legacy)'),
                'profile_key' => 'netmanager',
                'database' => env('SEED_SITE_NETMANAGER_DATABASE'),
                'username' => env('SEED_SITE_NETMANAGER_USERNAME'),
                'password' => env('SEED_SITE_NETMANAGER_PASSWORD'),
                'sort_order' => 2,
            ],
        ];

        $created = 0;

        foreach ($sites as $site) {
            if (empty($site['database']) || empty($site['username'])) {
                continue;
            }

            $existing = SiteConnection::where('key', $site['key'])->first();

            // Never clobber a connection an operator has since edited.
            if ($existing) {
                continue;
            }

            SiteConnection::create(array_merge($site, [
                'driver' => 'mysql',
                'host' => env('SEED_SITE_HOST', '127.0.0.1'),
                'port' => (int) env('SEED_SITE_PORT', 3306),
                'timezone' => '+08:00',
                'enabled' => true,
            ]));

            $created++;
        }

        if ($created === 0) {
            $this->command->warn('No site connections seeded — set SEED_SITE_* in .env, or add sites in the admin screen.');

            return;
        }

        $this->command->info("Seeded {$created} site connection(s).");
    }
}
