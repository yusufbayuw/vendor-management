<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->boolean('is_self_approval')->default(false);
            $table->text('comments')->nullable();
            $table->text('override_reason')->nullable();
            $table->timestamp('acted_at');
            $table->timestamps();

            $table->unique(['approval_request_id', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_actions');
    }
};
