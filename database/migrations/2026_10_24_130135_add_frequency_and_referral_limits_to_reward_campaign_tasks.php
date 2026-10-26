
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_campaign_tasks', function (Blueprint $table) {
            // Null means the task can be completed only once.
            // Positive values represent the interval in days between cycles.
            $table->unsignedSmallInteger('frequency_days')
                ->nullable()
                ->default(null);
        });

        Schema::table('reward_campaigns', function (Blueprint $table) {
            $table->unsignedInteger('referral_credit_limit')
                ->default(15);

            $table->string('referral_credit_period', 10)
                ->default('daily');
        });

        Schema::table('reward_task_progress', function (Blueprint $table) {
            $table->unsignedInteger('cycle_number')
                ->default(1);
        });

        Schema::table('reward_entitlements', function (Blueprint $table) {
            $table->unsignedInteger('cycle_number')
                ->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('reward_entitlements', function (Blueprint $table) {
            $table->dropColumn('cycle_number');
        });

        Schema::table('reward_task_progress', function (Blueprint $table) {
            $table->dropColumn('cycle_number');
        });

        Schema::table('reward_campaigns', function (Blueprint $table) {
            $table->dropColumn([
                'referral_credit_limit',
                'referral_credit_period',
            ]);
        });

        Schema::table('reward_campaign_tasks', function (Blueprint $table) {
            $table->dropColumn('frequency_days');
        });
    }
};
