<?php

namespace App\Services\Regions;

use Laravolt\Indonesia\Models\City;
use Laravolt\Indonesia\Models\District;
use Laravolt\Indonesia\Models\Province;
use Laravolt\Indonesia\Models\Village;

class IndonesiaRegionService
{
    /**
     * @return array<string, string>
     */
    public function provinces(): array
    {
        return Province::query()
            ->orderBy('name')
            ->pluck('name', 'code')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function cities(?string $provinceCode): array
    {
        if (blank($provinceCode)) {
            return [];
        }

        return City::query()
            ->where('province_code', $provinceCode)
            ->orderBy('name')
            ->pluck('name', 'code')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function districts(?string $cityCode): array
    {
        if (blank($cityCode)) {
            return [];
        }

        return District::query()
            ->where('city_code', $cityCode)
            ->orderBy('name')
            ->pluck('name', 'code')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function villages(?string $districtCode): array
    {
        if (blank($districtCode)) {
            return [];
        }

        return Village::query()
            ->where('district_code', $districtCode)
            ->orderBy('name')
            ->pluck('name', 'code')
            ->all();
    }
}
