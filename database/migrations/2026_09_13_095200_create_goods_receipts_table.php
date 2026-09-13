<?php

use App\Enums\GoodsReceiptStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_schedule_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('sppg_kitchen_id')->constrained()->restrictOnDelete();
            $table->timestamp('received_at');
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->string('supplier_representative')->nullable();
            $table->string('driver_name')->nullable();
            $table->string('vehicle_number', 50)->nullable();
            $table->string('delivery_note_number', 100)->nullable();
            $table->string('status', 40)->default(GoodsReceiptStatus::PendingInspection->value)->index();
            $table->text('notes')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['purchase_order_id', 'received_at']);
            $table->index(['delivery_schedule_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipts');
    }
};
