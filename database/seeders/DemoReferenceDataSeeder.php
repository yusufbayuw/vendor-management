<?php

namespace Database\Seeders;

use App\Enums\OperationalProfile;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SppgKitchen;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedBapanasCommodityReferences();
            $this->seedBgnSppgReferences();
        });
    }

    private function seedBapanasCommodityReferences(): void
    {
        $kg = Unit::query()->where('code', 'KG')->firstOrFail();
        $liter = Unit::query()->where('code', 'L')->firstOrFail();

        $categories = ProductCategory::query()
            ->whereIn('code', ['PROTEIN-HEWANI', 'PROTEIN-NABATI', 'BAHAN-POKOK', 'BUMBU', 'MINYAK-LEMAK', 'IKAN-HASIL-LAUT'])
            ->get()
            ->keyBy('code');

        $products = [
            ['code' => 'REF-BPN-BERAS-SPHP', 'name' => 'Beras SPHP', 'category' => 'BAHAN-POKOK', 'unit' => $kg, 'price' => 12558],
            ['code' => 'REF-BPN-BERAS-MEDIUM', 'name' => 'Beras Medium', 'category' => 'BAHAN-POKOK', 'unit' => $kg, 'price' => 13945],
            ['code' => 'REF-BPN-KEDELAI', 'name' => 'Kedelai Biji Kering', 'category' => 'PROTEIN-NABATI', 'unit' => $kg, 'price' => 10730],
            ['code' => 'REF-BPN-BAWANG-MERAH', 'name' => 'Bawang Merah', 'category' => 'BUMBU', 'unit' => $kg, 'price' => 41919],
            ['code' => 'REF-BPN-BAWANG-PUTIH', 'name' => 'Bawang Putih (Bonggol)', 'category' => 'BUMBU', 'unit' => $kg, 'price' => 37607],
            ['code' => 'REF-BPN-CABAI-RAWIT-MERAH', 'name' => 'Cabai Rawit Merah', 'category' => 'BUMBU', 'unit' => $kg, 'price' => 47269],
            ['code' => 'REF-BPN-DAGING-SAPI', 'name' => 'Daging Sapi Murni', 'category' => 'PROTEIN-HEWANI', 'unit' => $kg, 'price' => 135132],
            ['code' => 'REF-BPN-GULA-PASIR', 'name' => 'Gula Pasir Lokal/Curah', 'category' => 'BAHAN-POKOK', 'unit' => $kg, 'price' => 18151],
            ['code' => 'REF-BPN-MIGOR-CURAH', 'name' => 'Minyak Goreng Curah', 'category' => 'MINYAK-LEMAK', 'unit' => $liter, 'price' => 17542],
            ['code' => 'REF-BPN-IKAN-KEMBUNG', 'name' => 'Ikan Kembung', 'category' => 'IKAN-HASIL-LAUT', 'unit' => $kg, 'price' => 41785],
            ['code' => 'REF-BPN-IKAN-TONGKOL', 'name' => 'Ikan Tongkol', 'category' => 'IKAN-HASIL-LAUT', 'unit' => $kg, 'price' => 34751],
            ['code' => 'REF-BPN-IKAN-BANDENG', 'name' => 'Ikan Bandeng', 'category' => 'IKAN-HASIL-LAUT', 'unit' => $kg, 'price' => 35107],
            ['code' => 'REF-BPN-GARAM', 'name' => 'Garam Konsumsi', 'category' => 'BUMBU', 'unit' => $kg, 'price' => 11640],
            ['code' => 'REF-BPN-CABAI-MERAH-BESAR', 'name' => 'Cabai Merah Besar', 'category' => 'BUMBU', 'unit' => $kg, 'price' => 47270],
            ['code' => 'REF-BPN-MINYAKITA', 'name' => 'Minyak Kita', 'category' => 'MINYAK-LEMAK', 'unit' => $liter, 'price' => 17499],
        ];

        foreach ($products as $item) {
            Product::query()->updateOrCreate(
                ['code' => $item['code']],
                [
                    'category_id' => $categories[$item['category']]->getKey(),
                    'default_unit_id' => $item['unit']->getKey(),
                    'name' => $item['name'],
                    'description' => sprintf(
                        'Referensi publik Badan Pangan Nasional. Rata-rata harga konsumen nasional September 2025: Rp%s/%s. Nilai ini benchmark historis, bukan quotation supplier.',
                        number_format($item['price'], 0, ',', '.'),
                        $item['unit']->symbol,
                    ),
                    'is_active' => true,
                ],
            );
        }
    }

    private function seedBgnSppgReferences(): void
    {
        $organization = Organization::query()->updateOrCreate(
            ['code' => 'REF-BGN'],
            [
                'name' => 'Referensi Publik SPPG BGN (Demo)',
                'operational_profile' => OperationalProfile::Standard,
                'is_active' => true,
            ],
        );

        $kitchens = [
            [
                'code' => 'REF-SPPG-BDG-SUKALUYU',
                'name' => 'SPPG Kota Bandung Cibeunying Kaler Sukaluyu',
                'address' => 'Jl. Sidoluhur No.18-20, Sukaluyu, Kecamatan Cibeunying Kaler, Kota Bandung, Jawa Barat',
                'regency_code' => '32.73',
            ],
            [
                'code' => 'REF-SPPG-BDG-SEKELOA',
                'name' => 'SPPG Kota Bandung Coblong Lebak Gede 3',
                'address' => 'Jl. Kubang Utara II No.19 RT 002/RW 007, Kel. Sekeloa, Kec. Coblong, Kota Bandung, Jawa Barat',
                'regency_code' => '32.73',
            ],
            [
                'code' => 'REF-SPPG-BDG-SULAIMAN',
                'name' => 'SPPG Bandung Margahayu Sulaiman 2',
                'address' => 'Komplek Lanud Sulaiman, Jl. Albatros, Kel. Sulaiman, Kec. Margahayu, Kab. Bandung, Jawa Barat',
                'regency_code' => '32.04',
            ],
        ];

        foreach ($kitchens as $kitchen) {
            SppgKitchen::query()->updateOrCreate(
                ['code' => $kitchen['code']],
                [
                    'organization_id' => $organization->getKey(),
                    'name' => $kitchen['name'],
                    'address' => $kitchen['address'],
                    'province_code' => '32',
                    'regency_code' => $kitchen['regency_code'],
                    'phone' => null,
                    'is_active' => true,
                ],
            );
        }
    }
}
