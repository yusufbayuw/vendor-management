<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_import_batches', function (Blueprint $table): void {
            $table->boolean('dry_run')->default(false);
            $table->foreignId('source_preview_batch_id')->nullable()
                ->constrained('legacy_import_batches')->restrictOnDelete()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('legacy_import_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_preview_batch_id');
            $table->dropColumn('dry_run');
        });
    }
};
