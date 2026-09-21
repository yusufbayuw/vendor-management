<?php

use App\Enums\SupplierManagementMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('management_mode', 30)
                ->default(SupplierManagementMode::SelfService->value)
                ->after('supplier_type')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropIndex(['management_mode']);
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn('management_mode');
        });
    }
};
