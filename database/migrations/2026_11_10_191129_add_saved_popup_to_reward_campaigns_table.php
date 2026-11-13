<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_campaigns', function (Blueprint $table) {
            $table->boolean('saved_popup_enabled')->default(false);

            $table->string('saved_popup_title', 120)->nullable();

            $table->text('saved_popup_message')->nullable();

            $table->unsignedInteger('saved_popup_revision')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('reward_campaigns', function (Blueprint $table) {
            $table->dropColumn([
                'saved_popup_enabled',
                'saved_popup_title',
                'saved_popup_message',
                'saved_popup_revision',
            ]);
        });
    }
};