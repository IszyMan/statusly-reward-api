<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_saved_media', function (Blueprint $table) {
            // whatsapp or business.
            // Nullable to preserve any earlier test records.
            $table->string('source', 20)->nullable();
        });

        Schema::create('reward_save_credits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reward_task_progress_id')
                ->constrained('reward_task_progress')
                ->restrictOnDelete();

            $table->foreignId('reward_saved_media_id')
                ->constrained('reward_saved_media')
                ->restrictOnDelete();

            $table->timestamp('credited_at');

            $table->unique(
                ['reward_task_progress_id', 'reward_saved_media_id'],
                'reward_save_credits_progress_media_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_save_credits');

        Schema::table('reward_saved_media', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};