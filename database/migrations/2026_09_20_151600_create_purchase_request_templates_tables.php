<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_request_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sppg_kitchen_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['sppg_kitchen_id', 'name']);
        });

        Schema::create('purchase_request_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_template_id');
            $table->foreign('purchase_request_template_id', 'pr_template_item_template_fk')
                ->references('id')
                ->on('purchase_request_templates')
                ->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->text('quality_specification')->nullable();
            $table->decimal('requested_qty', 18, 4);
            $table->decimal('estimated_unit_price', 18, 2)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['purchase_request_template_id', 'sort_order'], 'pr_template_items_sort_idx');
            $table->index(['product_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_template_items');
        Schema::dropIfExists('purchase_request_templates');
    }
};
