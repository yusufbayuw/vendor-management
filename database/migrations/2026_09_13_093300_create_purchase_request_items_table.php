<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->text('quality_specification')->nullable();
            $table->decimal('requested_qty', 18, 4);
            $table->decimal('estimated_unit_price', 18, 2)->nullable();
            $table->decimal('estimated_total', 18, 2)->nullable();
            $table->date('preferred_delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['purchase_request_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_items');
    }
};
