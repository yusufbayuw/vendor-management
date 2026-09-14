<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Laravolt\Indonesia\Seeds\CitiesSeeder;
use Laravolt\Indonesia\Seeds\DistrictsSeeder;
use Laravolt\Indonesia\Seeds\ProvincesSeeder;
use Laravolt\Indonesia\Seeds\VillagesSeeder;

class IndonesiaRegionSeeder extends Seeder
{
    public function run(): void
    {
        $prefix = (string) config('laravolt.indonesia.table_prefix', 'indonesia_');

        if (DB::table($prefix.'provinces')->exists()
            && DB::table($prefix.'cities')->exists()
            && DB::table($prefix.'districts')->exists()
            && DB::table($prefix.'villages')->exists()) {
            return;
        }

        $this->call([
            ProvincesSeeder::class,
            CitiesSeeder::class,
            DistrictsSeeder::class,
            VillagesSeeder::class,
        ]);
    }
}
