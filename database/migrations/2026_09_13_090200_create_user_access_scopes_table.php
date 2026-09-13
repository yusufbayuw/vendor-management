<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_access_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 30);
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'scope_type', 'scope_id']);
            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_access_scopes');
    }
};
