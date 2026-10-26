<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_participants', function (Blueprint $table) {
            // Profiles already registered at enrolment cannot become
            // "new referrals" for that enrolment.
            $table->unsignedBigInteger('referral_device_id_floor')
                ->default(0);
        });

        // Existing participants start referring from this point onward.
        $latestDeviceId = (int) (
            DB::table('reward_devices')->max('id') ?? 0
        );

        DB::table('reward_participants')->update([
            'referral_device_id_floor' => $latestDeviceId,
        ]);

        Schema::create('reward_referrals', function (Blueprint $table) {
            $table->id();

            $table->string('environment', 10);

            $table->foreignId('inviter_device_id')
                ->constrained('reward_devices')
                ->restrictOnDelete();

            $table->foreignId('invited_device_id')
                ->unique()
                ->constrained('reward_devices')
                ->restrictOnDelete();

            $table->timestamp('attributed_at');

            $table->timestamps();
        });

        Schema::create('reward_referral_credits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reward_task_progress_id')
                ->constrained('reward_task_progress')
                ->restrictOnDelete();

            $table->foreignId('reward_referral_id')
                ->constrained('reward_referrals')
                ->restrictOnDelete();

            $table->timestamp('qualified_at');

            $table->unique(
                ['reward_task_progress_id', 'reward_referral_id'],
                'reward_referral_credits_progress_referral_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_referral_credits');
        Schema::dropIfExists('reward_referrals');

        Schema::table('reward_participants', function (Blueprint $table) {
            $table->dropColumn('referral_device_id_floor');
        });
    }
};