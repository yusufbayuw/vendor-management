<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('process', 80);
            $table->decimal('amount_threshold', 18, 2)->nullable();
            $table->boolean('self_approval_allowed')->default(true);
            $table->unsignedTinyInteger('minimum_approvers')->default(1);
            $table->boolean('requires_override_reason')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'process', 'is_active'], 'gov_policy_org_process_active_idx');
            $table->index(['organization_id', 'process', 'amount_threshold'], 'gov_policy_org_process_amount_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_policies');
    }
};
