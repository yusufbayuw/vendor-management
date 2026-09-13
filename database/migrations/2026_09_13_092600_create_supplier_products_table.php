<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('supplier_product_code')->nullable();
            $table->decimal('minimum_order_qty', 18, 4)->nullable();
            $table->decimal('maximum_order_qty', 18, 4)->nullable();
            $table->unsignedInteger('lead_time_days')->default(0);
            $table->decimal('indicative_price', 18, 2)->nullable();
            $table->boolean('is_available')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'product_id']);
            $table->index(['product_id', 'is_available']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_products');
    }
};
