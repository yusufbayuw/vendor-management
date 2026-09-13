<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_procurement_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('quantity_tolerance_percentage', 5, 2)->default(0);
            $table->boolean('requires_expiry_date')->default(false);
            $table->boolean('requires_batch_number')->default(false);
            $table->boolean('requires_temperature')->default(false);
            $table->boolean('requires_photo')->default(true);
            $table->boolean('requires_weight_photo')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_procurement_rules');
    }
};
