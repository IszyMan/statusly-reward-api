<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('reward_campaign_tasks')
                ->where('type', 'referrals')
                ->update([
                    'target_count' => 3,
                    'updated_at' => now(),
                ]);

            $progressRows = DB::table('reward_task_progress as progress')
                ->join(
                    'reward_campaign_tasks as tasks',
                    'tasks.id',
                    '=',
                    'progress.reward_campaign_task_id'
                )
                ->where('tasks.type', 'referrals')
                ->select([
                    'progress.id',
                    'progress.task_snapshot',
                ])
                ->get();

            foreach ($progressRows as $progress) {
                $snapshot = json_decode(
                    $progress->task_snapshot,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                $snapshot['target_count'] = 3;

                DB::table('reward_task_progress')
                    ->where('id', $progress->id)
                    ->update([
                        'task_snapshot' => json_encode(
                            $snapshot,
                            JSON_THROW_ON_ERROR
                        ),
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Do not automatically roll back referral milestones after rewards may have been issued.'
        );
    }
};
