
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_device_phone_numbers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reward_device_id')
                ->constrained('reward_devices')
                ->cascadeOnDelete();

            $table->char('phone_hash', 64)->unique();
            $table->string('network', 20);
            $table->string('phone_last4', 4);
            $table->timestamp('bound_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_device_phone_numbers');
    }
};
