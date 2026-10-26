<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_referral_qualifications', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('reward_task_progress_id');
            $table->unsignedBigInteger('reward_referral_id');

            $table->timestamp('eligible_at');
            $table->timestamp('credited_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['reward_task_progress_id', 'reward_referral_id'],
                'reward_referral_qualifications_progress_referral_unique'
            );

            $table->index(
                ['credited_at', 'eligible_at'],
                'reward_referral_qualifications_pending_index'
            );

            $table->foreign(
                'reward_task_progress_id',
                'reward_referral_qualifications_progress_foreign'
            )
                ->references('id')
                ->on('reward_task_progress')
                ->restrictOnDelete();

            $table->foreign(
                'reward_referral_id',
                'reward_referral_qualifications_referral_foreign'
            )
                ->references('id')
                ->on('reward_referrals')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_referral_qualifications');
    }
};
