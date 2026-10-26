<?php

namespace App\Console\Commands;

use App\Services\RewardRedemptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckPendingRewards extends Command
{
    protected $signature = 'rewards:check-pending';

    protected $description =
        'Check existing pending reward transactions without purchasing again';

    public function handle(RewardRedemptionService $service): int
    {
        // Matches our current redemption service restrictions.
        if (
            ! app()->environment('local', 'testing')
            || config('vtpass.environment') !== 'sandbox'
        ) {
            $this->warn(
                'Automatic reward checks currently support local sandbox only.'
            );

            return self::FAILURE;
        }

        // Also protects against simultaneous manual command runs.
        $lock = Cache::lock(
            'rewards:pending-check:'.config('vtpass.environment'),
            1800
        );

        if (! $lock->get()) {
            $this->info('Another pending-reward checker is running.');

            return self::SUCCESS;
        }

        try {
            $batchSize = max(
                1,
                min(50, (int) config('rewards.requery.batch_size', 10))
            );

            $maxAttempts = max(
                1,
                (int) config('rewards.requery.max_attempts', 12)
            );

            $minimumAge = max(
                30,
                (int) config(
                    'rewards.requery.minimum_age_seconds',
                    60
                )
            );

            $rows = DB::table('reward_redemptions as redemptions')
                ->join(
                    'reward_entitlements as rewards',
                    'rewards.id',
                    '=',
                    'redemptions.reward_entitlement_id'
                )
                ->join(
                    'reward_task_progress as progress',
                    'progress.id',
                    '=',
                    'rewards.reward_task_progress_id'
                )
                ->join(
                    'reward_participants as participants',
                    'participants.id',
                    '=',
                    'progress.reward_participant_id'
                )
                ->where(
                    'redemptions.environment',
                    config('vtpass.environment')
                )
                ->whereIn(
                    'redemptions.status',
                    ['submitting', 'pending']
                )
                ->where(
                    'redemptions.submitted_at',
                    '<=',
                    now()->subSeconds($minimumAge)
                )
                ->where(function ($query) {
                    $query->whereNull('redemptions.next_check_at')
                        ->orWhere(
                            'redemptions.next_check_at',
                            '<=',
                            now()
                        );
                })
                ->select([
                    'redemptions.id',
                    'redemptions.request_id',
                    'redemptions.auto_check_attempts',
                    'rewards.public_id as reward_id',
                    'participants.reward_device_id',
                ])
                ->orderBy('redemptions.next_check_at')
                ->orderBy('redemptions.id')
                ->limit($batchSize)
                ->get();

            if ($rows->isEmpty()) {
                $this->info('No pending reward transactions are due.');

                return self::SUCCESS;
            }

            foreach ($rows as $row) {
                if ((int) $row->auto_check_attempts >= $maxAttempts) {
                    $this->moveToReview($row->id);

                    $this->warn(
                        "{$row->request_id}: automatic check limit reached."
                    );

                    continue;
                }

                $delays = array_values(
                    config(
                        'rewards.requery.delays_seconds',
                        [60, 120, 300, 600, 1800, 3600]
                    )
                );

                if ($delays === []) {
                    $delays = [60];
                }

                $delayIndex = min(
                    (int) $row->auto_check_attempts,
                    count($delays) - 1
                );

                $delay = max(60, (int) $delays[$delayIndex]);

                // Save the next check time before contacting VTpass.
                // An interrupted process will not immediately hammer it.
                $updated = DB::table('reward_redemptions')
                    ->where('id', $row->id)
                    ->whereIn('status', ['submitting', 'pending'])
                    ->update([
                        'auto_check_attempts' => DB::raw(
                            'auto_check_attempts + 1'
                        ),
                        'next_check_at' => now()->addSeconds($delay),
                        'updated_at' => now(),
                    ]);

                if (! $updated) {
                    continue;
                }

                try {
                    // check() calls /requery, never /pay.
                    $result = $service->check(
                        (int) $row->reward_device_id,
                        $row->reward_id
                    );

                    $this->info(
                        "{$row->request_id}: {$result['status']}"
                    );
                } catch (Throwable $exception) {
                    // Do not log credentials or a full HTTP request.
                    Log::warning('Automatic reward check failed.', [
                        'redemption_id' => $row->id,
                        'exception_type' => class_basename($exception),
                    ]);

                    $this->warn(
                        "{$row->request_id}: check unavailable; "
                        .'a later check remains scheduled.'
                    );
                }
            }

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    private function moveToReview(int $redemptionId): void
    {
        DB::transaction(function () use ($redemptionId) {
            $redemption = DB::table('reward_redemptions')
                ->where('id', $redemptionId)
                ->lockForUpdate()
                ->first();

            if (! $redemption || ! in_array(
                $redemption->status,
                ['submitting', 'pending'],
                true
            )) {
                return;
            }

            $now = now();

            DB::table('reward_redemptions')
                ->where('id', $redemptionId)
                ->update([
                    'status' => 'review',
                    'message' => 'Automatic checks reached their limit. '
                        .'Administrator review is required.',
                    'next_check_at' => null,
                    'updated_at' => $now,
                ]);

            DB::table('reward_entitlements')
                ->where('id', $redemption->reward_entitlement_id)
                ->where('status', 'processing')
                ->update([
                    'status' => 'review',
                    'updated_at' => $now,
                ]);
        }, 3);
    }
}