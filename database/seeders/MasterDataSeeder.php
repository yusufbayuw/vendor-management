<?php

namespace Database\Seeders;

use App\Models\ProductCategory;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['code' => 'KG', 'name' => 'Kilogram', 'symbol' => 'kg', 'decimal_places' => 4],
            ['code' => 'G', 'name' => 'Gram', 'symbol' => 'g', 'decimal_places' => 2],
            ['code' => 'L', 'name' => 'Liter', 'symbol' => 'L', 'decimal_places' => 4],
            ['code' => 'ML', 'name' => 'Mililiter', 'symbol' => 'ml', 'decimal_places' => 2],
            ['code' => 'PCS', 'name' => 'Pieces', 'symbol' => 'pcs', 'decimal_places' => 0],
            ['code' => 'BOX', 'name' => 'Box', 'symbol' => 'box', 'decimal_places' => 0],
            ['code' => 'SACK', 'name' => 'Karung', 'symbol' => 'karung', 'decimal_places' => 0],
        ];

        foreach ($units as $unit) {
            Unit::query()->updateOrCreate(['code' => $unit['code']], $unit);
        }

        $categories = [
            ['code' => 'PROTEIN-HEWANI', 'name' => 'Protein Hewani'],
            ['code' => 'SAYURAN', 'name' => 'Sayuran'],
            ['code' => 'BAHAN-POKOK', 'name' => 'Bahan Pokok'],
            ['code' => 'BUAH', 'name' => 'Buah'],
            ['code' => 'BUMBU', 'name' => 'Bumbu dan Rempah'],
            ['code' => 'LAINNYA', 'name' => 'Lainnya'],
        ];

        foreach ($categories as $category) {
            ProductCategory::query()->updateOrCreate(['code' => $category['code']], $category);
        }
    }
}
