
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_task_cycles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reward_task_progress_id')
                ->constrained('reward_task_progress')
                ->restrictOnDelete();

            $table->unsignedInteger('cycle_number')->default(1);
            $table->unsignedInteger('progress_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('next_available_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['reward_task_progress_id', 'cycle_number'],
                'reward_task_cycles_progress_cycle_unique'
            );
        });

        Schema::table('reward_save_credits', function (Blueprint $table) {
            $table->foreignId('reward_task_cycle_id')
                ->nullable()
                ->constrained('reward_task_cycles')
                ->nullOnDelete();
        });

        Schema::table('reward_entitlements', function (Blueprint $table) {
            $table->foreignId('reward_task_cycle_id')
                ->nullable()
                ->constrained('reward_task_cycles')
                ->nullOnDelete();
        });

        DB::table('reward_task_progress')
            ->orderBy('id')
            ->chunkById(200, function ($progressRows) {
                foreach ($progressRows as $progress) {
                    $cycleId = DB::table('reward_task_cycles')
                        ->insertGetId([
                            'reward_task_progress_id' => $progress->id,
                            'cycle_number' => 1,
                            'progress_count' => $progress->progress_count,
                            'started_at' => $progress->created_at,
                            'completed_at' => $progress->completed_at,
                            'next_available_at' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                    DB::table('reward_save_credits')
                        ->where('reward_task_progress_id', $progress->id)
                        ->update([
                            'reward_task_cycle_id' => $cycleId,
                        ]);

                    DB::table('reward_entitlements')
                        ->where('reward_task_progress_id', $progress->id)
                        ->where('cycle_number', 1)
                        ->update([
                            'reward_task_cycle_id' => $cycleId,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('reward_entitlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reward_task_cycle_id');
        });

        Schema::table('reward_save_credits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reward_task_cycle_id');
        });

        Schema::dropIfExists('reward_task_cycles');
    }
};
