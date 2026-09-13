<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_schedule_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->decimal('planned_qty', 18, 4);
            $table->decimal('received_qty', 18, 4);
            $table->decimal('accepted_qty', 18, 4)->default(0);
            $table->decimal('rejected_qty', 18, 4)->default(0);
            $table->decimal('variance_qty', 18, 4)->default(0);
            $table->string('condition', 50)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('batch_number', 100)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('temperature', 8, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_item_id', 'goods_receipt_id']);
            $table->index('delivery_schedule_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
    }
};
