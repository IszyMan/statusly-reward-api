
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add a normal index first so the foreign key remains supported.
        Schema::table('reward_entitlements', function (Blueprint $table) {
            $table->index(
                'reward_task_progress_id',
                'reward_entitlements_progress_id_index'
            );
        });

        // The normal index now supports the foreign key.
        // We can safely remove the unique index.
        Schema::table('reward_entitlements', function (Blueprint $table) {
            $table->dropUnique(
                'reward_entitlements_reward_task_progress_id_unique'
            );
        });
    }

    public function down(): void
    {
        $hasDuplicateProgress = DB::table('reward_entitlements')
            ->select('reward_task_progress_id')
            ->groupBy('reward_task_progress_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateProgress) {
            throw new RuntimeException(
                'Cannot restore the unique constraint because multiple reward entitlements exist for a task progress record.'
            );
        }

        // Restore uniqueness before removing the supporting normal index.
        Schema::table('reward_entitlements', function (Blueprint $table) {
            $table->unique(
                'reward_task_progress_id',
                'reward_entitlements_reward_task_progress_id_unique'
            );
        });

        Schema::table('reward_entitlements', function (Blueprint $table) {
            $table->dropIndex(
                'reward_entitlements_progress_id_index'
            );
        });
    }
};
