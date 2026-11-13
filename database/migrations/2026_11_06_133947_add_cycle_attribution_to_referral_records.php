
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_referral_qualifications', function (Blueprint $table) {
            $table->unsignedBigInteger('reward_task_cycle_id')
                ->nullable()
                ->after('reward_task_progress_id');

            $table->index(
                'reward_task_cycle_id',
                'referral_qualifications_cycle_index'
            );

            $table->foreign(
                'reward_task_cycle_id',
                'referral_qualifications_cycle_foreign'
            )
                ->references('id')
                ->on('reward_task_cycles')
                ->restrictOnDelete();
        });

        Schema::table('reward_referral_credits', function (Blueprint $table) {
            $table->unsignedBigInteger('reward_task_cycle_id')
                ->nullable()
                ->after('reward_task_progress_id');

            $table->index(
                'reward_task_cycle_id',
                'referral_credits_cycle_index'
            );

            $table->foreign(
                'reward_task_cycle_id',
                'referral_credits_cycle_foreign'
            )
                ->references('id')
                ->on('reward_task_cycles')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reward_referral_credits', function (Blueprint $table) {
            $table->dropForeign('referral_credits_cycle_foreign');
            $table->dropIndex('referral_credits_cycle_index');
            $table->dropColumn('reward_task_cycle_id');
        });

        Schema::table('reward_referral_qualifications', function (Blueprint $table) {
            $table->dropForeign('referral_qualifications_cycle_foreign');
            $table->dropIndex('referral_qualifications_cycle_index');
            $table->dropColumn('reward_task_cycle_id');
        });
    }
};
