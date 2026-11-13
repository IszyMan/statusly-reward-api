<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_referral_qualifications', function (Blueprint $table) {
            $table->timestamp('discarded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reward_referral_qualifications', function (Blueprint $table) {
            $table->dropColumn('discarded_at');
        });
    }
};