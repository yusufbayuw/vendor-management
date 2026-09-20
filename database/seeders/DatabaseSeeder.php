<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
            MasterDataSeeder::class,
        ]);

        if (! app()->environment('testing')) {
            $this->call(IndonesiaRegionSeeder::class);
        }

        if (! app()->environment('production')) {
            $this->call([
                DemoReferenceDataSeeder::class,
                DemoDataSeeder::class,
                DemoAnalyticsSeeder::class,
                DemoUserSeeder::class,
            ]);
        }
    }
}
