<?php

use App\Enums\DeliveryScheduleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->dateTime('planned_delivery_at');
            $table->dateTime('estimated_arrival_at')->nullable();
            $table->string('status', 40)->default(DeliveryScheduleStatus::Planned->value)->index();
            $table->string('driver_name')->nullable();
            $table->string('driver_phone', 40)->nullable();
            $table->string('vehicle_number', 50)->nullable();
            $table->string('delivery_note_number', 100)->nullable();
            $table->string('delivery_note_file')->nullable();
            $table->text('supplier_notes')->nullable();
            $table->text('kitchen_notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'planned_delivery_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_schedules');
    }
};
