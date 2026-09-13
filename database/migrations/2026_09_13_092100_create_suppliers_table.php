<?php

use App\Enums\SupplierStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('legal_name');
            $table->string('display_name')->nullable();
            $table->string('supplier_type', 40)->default('company');
            $table->string('npwp', 40)->nullable()->index();
            $table->string('nib', 80)->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('phone', 40)->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->string('province_code', 20)->nullable();
            $table->string('regency_code', 20)->nullable();
            $table->string('district_code', 20)->nullable();
            $table->string('village_code', 20)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('status', 40)->default(SupplierStatus::Draft->value)->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['province_code', 'regency_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
