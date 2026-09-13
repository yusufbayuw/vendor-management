<?php

use App\Enums\VerificationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('bank_code', 30)->nullable();
            $table->string('bank_name');
            $table->string('account_number', 100);
            $table->string('account_holder');
            $table->boolean('is_primary')->default(false);
            $table->string('verification_status', 30)->default(VerificationStatus::Pending->value);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_bank_accounts');
    }
};
