<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->json('onboarding_exemptions')->nullable();
        });

        Schema::table('supplier_documents', function (Blueprint $table) {
            $table->text('verification_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_documents', function (Blueprint $table) {
            $table->dropColumn('verification_note');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('onboarding_exemptions');
        });
    }
};
