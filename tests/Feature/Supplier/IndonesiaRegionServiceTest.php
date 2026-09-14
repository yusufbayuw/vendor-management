<?php

namespace Tests\Feature\Supplier;

use App\Services\Regions\IndonesiaRegionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IndonesiaRegionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_region_options_follow_the_selected_parent(): void
    {
        $prefix = (string) config('laravolt.indonesia.table_prefix', 'indonesia_');
        $now = now();

        DB::table($prefix.'provinces')->insert([
            ['code' => '32', 'name' => 'JAWA BARAT', 'created_at' => $now, 'updated_at' => $now],
            ['code' => '33', 'name' => 'JAWA TENGAH', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table($prefix.'cities')->insert([
            ['code' => '3273', 'province_code' => '32', 'name' => 'KOTA BANDUNG', 'created_at' => $now, 'updated_at' => $now],
            ['code' => '3374', 'province_code' => '33', 'name' => 'KOTA SEMARANG', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table($prefix.'districts')->insert([
            ['code' => '3273010', 'city_code' => '3273', 'name' => 'SUKASARI', 'created_at' => $now, 'updated_at' => $now],
            ['code' => '3374010', 'city_code' => '3374', 'name' => 'MIJEN', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table($prefix.'villages')->insert([
            ['code' => '3273010001', 'district_code' => '3273010', 'name' => 'ISOLA', 'created_at' => $now, 'updated_at' => $now],
            ['code' => '3374010001', 'district_code' => '3374010', 'name' => 'CANGKIRAN', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $service = app(IndonesiaRegionService::class);

        $this->assertSame('JAWA BARAT', $service->provinces()['32']);
        $this->assertSame(['3273' => 'KOTA BANDUNG'], $service->cities('32'));
        $this->assertSame(['3273010' => 'SUKASARI'], $service->districts('3273'));
        $this->assertSame(['3273010001' => 'ISOLA'], $service->villages('3273010'));
        $this->assertSame([], $service->cities(null));
        $this->assertSame([], $service->districts(null));
        $this->assertSame([], $service->villages(null));
    }
}
