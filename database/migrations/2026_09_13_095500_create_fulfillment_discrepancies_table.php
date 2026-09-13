<?php

use App\Enums\DiscrepancyStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_discrepancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('goods_receipt_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 50);
            $table->decimal('expected_qty', 18, 4)->nullable();
            $table->decimal('actual_qty', 18, 4)->nullable();
            $table->decimal('variance_qty', 18, 4)->nullable();
            $table->text('description')->nullable();
            $table->string('resolution', 50)->nullable();
            $table->text('resolution_notes')->nullable();
            $table->string('status', 30)->default(DiscrepancyStatus::Open->value)->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_discrepancies');
    }
};
