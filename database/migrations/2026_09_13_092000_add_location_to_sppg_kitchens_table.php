<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sppg_kitchens', function (Blueprint $table) {
            $table->string('province_code', 20)->nullable()->after('address');
            $table->string('regency_code', 20)->nullable()->after('province_code');
            $table->string('district_code', 20)->nullable()->after('regency_code');
            $table->string('village_code', 20)->nullable()->after('district_code');
            $table->string('postal_code', 10)->nullable()->after('village_code');

            $table->index(['province_code', 'regency_code']);
        });
    }

    public function down(): void
    {
        Schema::table('sppg_kitchens', function (Blueprint $table) {
            $table->dropIndex(['province_code', 'regency_code']);
            $table->dropColumn([
                'province_code',
                'regency_code',
                'district_code',
                'village_code',
                'postal_code',
            ]);
        });
    }
};
