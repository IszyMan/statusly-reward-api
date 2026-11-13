<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_campaign_popups', function (Blueprint $table) {
            $table->id();

            // Stable identity used by Flutter to remember dismissals.
            $table->string('announcement_key', 100)->unique();

            $table->foreignId('reward_campaign_id')
                ->constrained('reward_campaigns')
                ->restrictOnDelete();

            // Internal admin name.
            $table->string('name', 160);

            $table->string('heading', 120);
            $table->string('title', 160);
            $table->text('message')->nullable();

            // Supported by CampaignPopupDialog in Flutter.
            $table->string('template', 30)->default('gift_card');

            $table->string('background_start', 7)->default('#E0FCFC');
            $table->string('background_end', 7)->default('#99F3E9');
            $table->string('accent_color', 7)->default('#00BFA5');
            $table->string('text_color', 7)->default('#173B36');
            $table->string('button_text_color', 7)->default('#FFFFFF');

            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->unsignedInteger('revision')->default(1);

            $table->timestamps();

            $table->index(['enabled', 'priority']);
        });

        // Preserve existing campaign-based announcements.
        if (Schema::hasColumns('reward_campaigns', [
            'saved_popup_enabled',
            'saved_popup_title',
            'saved_popup_message',
            'saved_popup_revision',
        ])) {
            DB::table('reward_campaigns')
                ->orderBy('id')
                ->chunkById(100, function ($campaigns) {
                    foreach ($campaigns as $campaign) {
                        $title = trim(
                            (string) $campaign->saved_popup_title
                        );

                        $message = trim(
                            (string) $campaign->saved_popup_message
                        );

                        if (
                            ! $campaign->saved_popup_enabled
                            && $title === ''
                            && $message === ''
                        ) {
                            continue;
                        }

                        DB::table('reward_campaign_popups')->insert([
                            // Retains the old dismissal identity.
                            'announcement_key' => $campaign->code,
                            'reward_campaign_id' => $campaign->id,
                            'name' => mb_substr(
                                $campaign->name.' announcement',
                                0,
                                160
                            ),
                            'heading' => 'Rewards available!',
                            'title' => mb_substr(
                                $title !== '' ? $title : $campaign->name,
                                0,
                                160
                            ),
                            'message' => $message !== ''
                                ? $message
                                : $campaign->description,
                            'template' => 'gift_card',
                            'background_start' => '#E0FCFC',
                            'background_end' => '#99F3E9',
                            'accent_color' => '#00BFA5',
                            'text_color' => '#173B36',
                            'button_text_color' => '#FFFFFF',
                            'enabled' => (bool) $campaign->saved_popup_enabled,
                            'priority' => 0,
                            'revision' => max(
                                1,
                                (int) $campaign->saved_popup_revision
                            ),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_campaign_popups');
    }
};