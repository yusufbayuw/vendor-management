<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->decimal('planned_qty', 18, 4);
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['delivery_schedule_id', 'purchase_order_item_id'], 'delivery_schedule_items_schedule_po_item_unique');
            $table->index('purchase_order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_schedule_items');
    }
};
