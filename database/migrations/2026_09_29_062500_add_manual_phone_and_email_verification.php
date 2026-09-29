<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone_verification_method', 40)->nullable()->after('phone_verified_at');
            $table->foreignId('phone_verified_by')
                ->nullable()
                ->after('phone_verification_method')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::create('phone_verification_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 30)->index();
            $table->string('method', 40)->default('whatsapp_manual')->index();
            $table->string('reference', 32)->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('requested_at')->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_verification_requests');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('phone_verified_by');
            $table->dropColumn('phone_verification_method');
        });
    }
};
