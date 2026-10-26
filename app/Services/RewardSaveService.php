<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RewardSaveService
{
    public function record(int $deviceId, array $data): array
    {
        return DB::transaction(function () use ($deviceId, $data) {
            $campaigns = DB::table('reward_campaigns')
                ->where('environment', config('vtpass.environment'))
                ->whereIn(
                    'id',
                    DB::table('reward_participants')
                        ->where('reward_device_id', $deviceId)
                        ->select('reward_campaign_id')
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $device = DB::table('reward_devices')
                ->where('id', $deviceId)
                ->lockForUpdate()
                ->first();

            abort_unless($device, 401, 'Device profile unavailable.');

            abort_if(
                $device->blocked_at !== null,
                403,
                'Rewards access is unavailable for this device.'
            );

            $contentHash = strtolower($data['content_hash']);

            $existing = DB::table('reward_saved_media')
                ->where('reward_device_id', $deviceId)
                ->where('content_hash', $contentHash)
                ->first();

            if ($existing) {
                return [
                    'accepted' => false,
                    'duplicate' => true,
                    'credited_tasks' => [],
                    'earned_rewards' => [],
                ];
            }

            $now = now();

            $mediaId = DB::table('reward_saved_media')->insertGetId([
                'reward_device_id' => $deviceId,
                'content_hash' => $contentHash,
                'media_type' => $data['media_type'],
                'source' => $data['source'],
                'size_bytes' => $data['size_bytes'],
                'accepted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $progressRows = DB::table('reward_task_progress as progress')
                ->join(
                    'reward_participants as participants',
                    'participants.id',
                    '=',
                    'progress.reward_participant_id'
                )
                ->where('participants.reward_device_id', $deviceId)
                ->whereIn(
                    'participants.reward_campaign_id',
                    $campaigns->keys()->all()
                )
                ->select([
                    'progress.*',
                    'participants.id as participant_id',
                    'participants.reward_campaign_id',
                    'participants.enrolled_at',
                ])
                ->orderBy('progress.id')
                ->lockForUpdate()
                ->get();

            $creditedTasks = [];
            $earnedRewards = [];

            foreach ($progressRows as $progress) {
                $campaign = $campaigns->get(
                    $progress->reward_campaign_id
                );

                if (! $campaign) {
                    continue;
                }

                if (! in_array(
                    $campaign->status,
                    ['active', 'paused'],
                    true
                )) {
                    continue;
                }

                if (
                    $campaign->starts_at
                    && CarbonImmutable::parse(
                        $campaign->starts_at
                    )->greaterThan($now)
                ) {
                    continue;
                }

                if (
                    $campaign->tasks_close_at
                    && CarbonImmutable::parse(
                        $campaign->tasks_close_at
                    )->lessThanOrEqualTo($now)
                ) {
                    continue;
                }

                if (
                    CarbonImmutable::parse(
                        $progress->enrolled_at
                    )->greaterThan($now)
                ) {
                    continue;
                }

                $task = json_decode(
                    $progress->task_snapshot,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                if (($task['type'] ?? null) !== 'save_media') {
                    continue;
                }

                if (
                    ($task['media_type'] ?? 'all') !== 'all'
                    && $task['media_type'] !== $data['media_type']
                ) {
                    continue;
                }

                if (
                    ($task['source'] ?? 'all') !== 'all'
                    && $task['source'] !== $data['source']
                ) {
                    continue;
                }

                $target = (int) $task['target_count'];
                $frequencyDays = $task['frequency_days'] ?? null;
                $currentCycleNumber = (int) $progress->cycle_number;

                $cycle = DB::table('reward_task_cycles')
                    ->where('reward_task_progress_id', $progress->id)
                    ->where('cycle_number', $currentCycleNumber)
                    ->lockForUpdate()
                    ->first();

                if (! $cycle) {
                    throw new \RuntimeException(
                        'The current task cycle is missing.'
                    );
                }

                
                /*
                 * One-time tasks remain complete permanently.
                 * Recurring tasks prepare a new cycle after completion.
                 */
                if ($cycle->completed_at !== null) {
                    if ($frequencyDays === null) {
                        continue;
                    }

                    $nextCycleNumber = $currentCycleNumber + 1;

                    $nextCycle = DB::table('reward_task_cycles')
                        ->where('reward_task_progress_id', $progress->id)
                        ->where('cycle_number', $nextCycleNumber)
                        ->lockForUpdate()
                        ->first();

                    if (! $nextCycle) {
                        $nextAvailableAt = CarbonImmutable::parse(
                            $cycle->completed_at
                        )->addDays((int) $frequencyDays);

                        $nextCycleId = DB::table('reward_task_cycles')
                            ->insertGetId([
                                'reward_task_progress_id' => $progress->id,
                                'cycle_number' => $nextCycleNumber,
                                'progress_count' => 0,
                                'started_at' => null,
                                'completed_at' => null,
                                'next_available_at' => $nextAvailableAt,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);

                        $nextCycle = DB::table('reward_task_cycles')
                            ->where('id', $nextCycleId)
                            ->lockForUpdate()
                            ->first();
                    }

                    if (
                        $nextCycle->next_available_at === null
                        || CarbonImmutable::parse(
                            $nextCycle->next_available_at
                        )->greaterThan($now)
                    ) {
                        continue;
                    }

                    /*
                     * Reserve the next cycle's reward before allowing
                     * progress. Never begin an unfunded reward cycle.
                     */
                    $rewardCost = (int) $task['max_reward_cost_kobo'];

                    $availableBudget = (int) $campaign->budget_kobo
                        - (int) $campaign->allocated_budget_kobo;

                    if ($availableBudget < $rewardCost) {
                        continue;
                    }

                    DB::table('reward_campaigns')
                        ->where('id', $campaign->id)
                        ->increment('allocated_budget_kobo', $rewardCost, [
                            'updated_at' => $now,
                        ]);

                    DB::table('reward_participants')
                        ->where('id', $progress->participant_id)
                        ->increment('reserved_budget_kobo', $rewardCost, [
                            'updated_at' => $now,
                        ]);

                    $campaign->allocated_budget_kobo =
                        (int) $campaign->allocated_budget_kobo + $rewardCost;

                    DB::table('reward_task_cycles')
                        ->where('id', $nextCycle->id)
                        ->update([
                            'started_at' => $now,
                            'updated_at' => $now,
                        ]);

                    DB::table('reward_task_progress')
                        ->where('id', $progress->id)
                        ->update([
                            'cycle_number' => $nextCycleNumber,
                            'progress_count' => 0,
                            'completed_at' => null,
                            'updated_at' => $now,
                        ]);

                    $currentCycleNumber = $nextCycleNumber;

                    $cycle = DB::table('reward_task_cycles')
                        ->where('id', $nextCycle->id)
                        ->first();

                    $progress->cycle_number = $nextCycleNumber;
                    $progress->progress_count = 0;
                    $progress->completed_at = null;
                }


                if (
                    (int) $cycle->progress_count >= $target
                    || $cycle->completed_at !== null
                ) {
                    continue;
                }

                DB::table('reward_save_credits')->insert([
                    'reward_task_progress_id' => $progress->id,
                    'reward_task_cycle_id' => $cycle->id,
                    'reward_saved_media_id' => $mediaId,
                    'credited_at' => $now,
                ]);

                $next = (int) $cycle->progress_count + 1;
                $completed = $next >= $target;

                DB::table('reward_task_cycles')
                    ->where('id', $cycle->id)
                    ->update([
                        'progress_count' => $next,
                        'completed_at' => $completed ? $now : null,
                        'updated_at' => $now,
                    ]);

                DB::table('reward_task_progress')
                    ->where('id', $progress->id)
                    ->update([
                        'progress_count' => $next,
                        'cycle_number' => $currentCycleNumber,
                        'completed_at' => $completed ? $now : null,
                        'updated_at' => $now,
                    ]);

                $creditedTasks[] = [
                    'progress_id' => $progress->id,
                    'cycle_number' => $currentCycleNumber,
                    'title' => $task['title'],
                    'progress_count' => $next,
                    'target_count' => $target,
                    'completed' => $completed,
                ];

                if ($completed) {
                    $rewardId = (string) Str::uuid();

                    DB::table('reward_entitlements')->insert([
                        'public_id' => $rewardId,
                        'reward_task_progress_id' => $progress->id,
                        'reward_task_cycle_id' => $cycle->id,
                        'cycle_number' => $currentCycleNumber,
                        'status' => 'earned',
                        'max_cost_kobo' => (int) $task['max_reward_cost_kobo'],
                        'bundle_snapshot' => $progress->bundle_snapshot,
                        'earned_at' => $now,
                        'delivered_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $earnedRewards[] = [
                        'reward_id' => $rewardId,
                        'progress_id' => $progress->id,
                        'cycle_number' => $currentCycleNumber,
                        'status' => 'earned',
                    ];
                }
            }

            return [
                'accepted' => true,
                'duplicate' => false,
                'credited_tasks' => $creditedTasks,
                'earned_rewards' => $earnedRewards,
            ];
        }, 3);
    }
}
