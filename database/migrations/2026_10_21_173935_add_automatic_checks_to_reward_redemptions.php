<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_redemptions', function (Blueprint $table) {
            $table->unsignedInteger('auto_check_attempts')->default(0);

            $table->timestamp('next_check_at')->nullable();

            $table->index(
                ['environment', 'status', 'next_check_at'],
                'reward_redemptions_auto_check_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('reward_redemptions', function (Blueprint $table) {
            $table->dropIndex(
                'reward_redemptions_auto_check_index'
            );

            $table->dropColumn([
                'auto_check_attempts',
                'next_check_at',
            ]);
        });
    }
};