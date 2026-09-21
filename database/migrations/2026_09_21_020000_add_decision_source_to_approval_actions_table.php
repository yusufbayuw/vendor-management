<?php

use App\Enums\ApprovalDecisionSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_actions', function (Blueprint $table): void {
            $table->string('decision_source', 40)
                ->default(ApprovalDecisionSource::Manual->value)
                ->after('is_self_approval')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('approval_actions', function (Blueprint $table): void {
            $table->dropIndex(['decision_source']);
        });

        Schema::table('approval_actions', function (Blueprint $table): void {
            $table->dropColumn('decision_source');
        });
    }
};
