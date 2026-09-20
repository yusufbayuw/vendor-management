<?php

namespace App\Services\Regions;

use Illuminate\Support\Facades\Schema;
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
        if (! $this->tableExists(Province::class)) {
            return [];
        }

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
        if (blank($provinceCode) || ! $this->tableExists(City::class)) {
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
        if (blank($cityCode) || ! $this->tableExists(District::class)) {
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
        if (blank($districtCode) || ! $this->tableExists(Village::class)) {
            return [];
        }

        return Village::query()
            ->where('district_code', $districtCode)
            ->orderBy('name')
            ->pluck('name', 'code')
            ->all();
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     */
    private function tableExists(string $modelClass): bool
    {
        return Schema::hasTable((new $modelClass)->getTable());
    }
}
