<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_provenances', function (Blueprint $table): void {
            $table->id();
            $table->morphs('sourceable');
            $table->foreignId('legacy_import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provenance_type', 40)->default('legacy_import')->index();
            $table->string('source_file');
            $table->string('source_sheet')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_key')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['provenance_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_provenances');
    }
};
