<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->id();

            // One redemption record per earned reward.
            $table->foreignId('reward_entitlement_id')
                ->unique()
                ->constrained('reward_entitlements')
                ->restrictOnDelete();

            $table->string('environment', 10);
            $table->string('request_id', 60)->unique();

            $table->string('network', 20);
            $table->string('phone', 20);
            $table->json('bundle_snapshot');
            $table->unsignedBigInteger('quoted_price_kobo');

            // submitting, pending, delivered, or review.
            $table->string('status', 20);

            $table->string('provider_code', 20)->nullable();
            $table->string('provider_status', 40)->nullable();
            $table->string('provider_transaction_id', 120)->nullable();

            $table->string('message', 500)->nullable();

            $table->timestamp('submitted_at');
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['environment', 'provider_transaction_id'],
                'reward_redemptions_environment_transaction_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_redemptions');
    }
};