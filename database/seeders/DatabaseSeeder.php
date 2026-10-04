<?php

namespace Database\Seeders;

use App\Integration\IntegrationBootstrap;
use App\Models\User;
use App\Services\Core\SettingsService;
use App\Support\SnapshotImporter;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demo / development data: the validated prototype data set, taken as a snapshot of the reference system
 * (database/seed-data/snapshot.json) so both systems start from identical, engine-consistent stock figures.
 *
 * Idempotent: does nothing when users already exist. Re-seed from scratch with `php artisan migrate:fresh --seed`.
 * Every demo user gets SEED_PASSWORD from the environment — never a password stored in the repository.
 */
class DatabaseSeeder extends Seeder
{
    public function run(SnapshotImporter $importer, SettingsService $settings): void
    {
        if (User::count() > 0) {
            $this->command?->info('[seed] database already has users — skipping (use migrate:fresh --seed to reseed)');

            return;
        }
        $password = config('scm_auth.seed_password');
        if (! $password) {
            throw new RuntimeException('Set SEED_PASSWORD in .env before seeding (the demo users need a password).');
        }
        $counts = $importer->import(database_path('seed-data/snapshot.json'), resetPasswordTo: $password);
        $settings->ensureDefaults();
        IntegrationBootstrap::ensure(); // the snapshot replaced the access tables: integration permissions + service users
        $this->call(DemoCoordinatesSeeder::class);
        $this->command?->info('[seed] imported '.array_sum($counts).' rows into '.count(array_filter($counts)).' tables');
        $this->command?->info('[seed] logins: admin, sales, wm, inv, proc, disp, worker, driver, gm, finance — password = SEED_PASSWORD');
    }
}
