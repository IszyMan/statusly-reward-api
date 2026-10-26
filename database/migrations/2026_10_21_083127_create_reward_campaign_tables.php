<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_campaigns', function (Blueprint $table) {
            $table->id();

            $table->string('code', 60)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('terms')->nullable();

            // sandbox or live.
            $table->string('environment', 10)->default('sandbox');

            // draft, active, paused, or closed.
            $table->string('status', 20)->default('draft');

            // all or new_devices.
            $table->string('audience', 20)->default('all');

            // All monetary values are in kobo.
            $table->unsignedBigInteger('budget_kobo');

            // Reserved obligations, including delivered rewards.
            $table->unsignedBigInteger('allocated_budget_kobo')
                ->default(0);

            $table->unsignedInteger('participant_limit');
            $table->unsignedInteger('enrolled_count')->default(0);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('enrollment_closes_at')->nullable();

            // Optional deadline for completing tasks.
            $table->timestamp('tasks_close_at')->nullable();

            $table->timestamps();
        });

        Schema::create('reward_campaign_tasks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reward_campaign_id')
                ->constrained('reward_campaigns')
                ->restrictOnDelete();

            $table->string('code', 60);
            $table->string('title');
            $table->text('description')->nullable();

            // save_media or referrals.
            $table->string('type', 30);

            $table->unsignedInteger('target_count');

            // all, images, or videos. Used for save tasks.
            $table->string('media_type', 10)->default('all');

            // all, whatsapp, or business.
            $table->string('source', 20)->default('all');

            // Referral qualification rules, such as required saves.
            $table->json('qualification_rules')->nullable();

            // Highest permitted cost for this task's single reward.
            $table->unsignedBigInteger('max_reward_cost_kobo');

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(
                ['reward_campaign_id', 'code'],
                'reward_tasks_campaign_code_unique'
            );
        });

        Schema::create('reward_task_bundles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reward_campaign_task_id')
                ->constrained('reward_campaign_tasks')
                ->restrictOnDelete();

            // mtn, airtel, or glo.
            $table->string('network', 20);

            $table->string('service_id', 80);
            $table->string('variation_code', 120);

            // Display the actual network-specific offer.
            $table->string('bundle_name');
            $table->string('validity_label')->nullable();

            // Optional structured size; not inferred from variation code.
            $table->unsignedBigInteger('data_amount_mb')->nullable();

            $table->unsignedBigInteger('listed_price_kobo');

            $table->boolean('enabled')->default(true);
            $table->timestamp('catalogue_checked_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['reward_campaign_task_id', 'network'],
                'reward_task_bundles_task_network_unique'
            );
        });

        Schema::create('reward_participants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reward_campaign_id')
                ->constrained('reward_campaigns')
                ->restrictOnDelete();

            $table->foreignId('reward_device_id')
                ->constrained('reward_devices')
                ->restrictOnDelete();

            // Sum of the enrolled tasks' reward cost limits.
            $table->unsignedBigInteger('reserved_budget_kobo');

            $table->timestamp('enrolled_at');

            $table->timestamps();

            $table->unique(
                ['reward_campaign_id', 'reward_device_id'],
                'reward_participants_campaign_device_unique'
            );
        });

        Schema::create('reward_task_progress', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reward_participant_id')
                ->constrained('reward_participants')
                ->restrictOnDelete();

            $table->foreignId('reward_campaign_task_id')
                ->constrained('reward_campaign_tasks')
                ->restrictOnDelete();

            $table->unsignedInteger('progress_count')->default(0);

            // Snapshot the participant's agreed task and offers.
            $table->json('task_snapshot');
            $table->json('bundle_snapshot');

            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['reward_participant_id', 'reward_campaign_task_id'],
                'reward_progress_participant_task_unique'
            );
        });

        Schema::create('reward_entitlements', function (Blueprint $table) {
            $table->id();

            $table->uuid('public_id')->unique();

            // Each enrolled task earns one reward.
            $table->foreignId('reward_task_progress_id')
                ->unique()
                ->constrained('reward_task_progress')
                ->restrictOnDelete();

            // earned, processing, delivered, or review.
            $table->string('status', 20)->default('earned');

            $table->unsignedBigInteger('max_cost_kobo');

            // Preserve the offers attached to the earned reward.
            $table->json('bundle_snapshot');

            $table->timestamp('earned_at');
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_entitlements');
        Schema::dropIfExists('reward_task_progress');
        Schema::dropIfExists('reward_participants');
        Schema::dropIfExists('reward_task_bundles');
        Schema::dropIfExists('reward_campaign_tasks');
        Schema::dropIfExists('reward_campaigns');
    }
};