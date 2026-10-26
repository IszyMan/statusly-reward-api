<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_devices', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index();
        });

        Schema::create('reward_device_reset_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reward_device_id')
                ->constrained('reward_devices')
                ->restrictOnDelete();

            $table->unsignedBigInteger('admin_user_id');
            $table->string('environment', 10);

            // session or sandbox_restart.
            $table->string('action', 30);

            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_device_reset_logs');

        Schema::table('reward_devices', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};