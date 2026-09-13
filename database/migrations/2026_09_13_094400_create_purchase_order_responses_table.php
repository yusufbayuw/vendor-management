<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->string('response', 40);
            $table->foreignId('responded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('responded_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'response']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_responses');
    }
};
