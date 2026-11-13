<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RewardReferralService
{
    /*
    |--------------------------------------------------------------------------
    | Claim Referral Code
    |--------------------------------------------------------------------------
    */

    public function claim(int $deviceId, string $code): array
    {
        $code = strtoupper(trim($code));

        return DB::transaction(function () use ($deviceId, $code) {

            $inviter = DB::table('reward_devices')
                ->where('referral_code', $code)
                ->first();

            if (! $inviter) {
                $this->invalid('This referral code was not found.');
            }

            if ((int) $inviter->id === $deviceId) {
                $this->invalid('You cannot use your own referral code.');
            }

            $devices = DB::table('reward_devices')
                ->whereIn('id', [$deviceId, $inviter->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $device = $devices->get($deviceId);
            $inviter = $devices->get($inviter->id);

            abort_unless(
                $device && $inviter,
                404,
                'Device unavailable.'
            );

            if (
                $device->blocked_at !== null
                || $inviter->blocked_at !== null
                || $device->archived_at !== null
                || $inviter->archived_at !== null
            ) {
                $this->invalid(
                    'This device cannot participate in referrals.'
                );
            }

            $existing = DB::table('reward_referrals')
                ->where('invited_device_id', $deviceId)
                ->first();

            if ($existing) {
                if (
                    (int) $existing->inviter_device_id === (int) $inviter->id
                    && $existing->environment === config('vtpass.environment')
                ) {
                    return [
                        'claimed' => true,
                        'already_claimed' => true,
                    ];
                }

                $this->invalid(
                    'This device already has a different referral attribution.'
                );
            }

            if ((int) $inviter->id >= $deviceId) {
                $this->invalid(
                    'The inviter must have registered before this device.'
                );
            }

            if (
                DB::table('reward_saved_media')
                    ->where('reward_device_id', $deviceId)
                    ->exists()
                ||
                DB::table('reward_participants')
                    ->where('reward_device_id', $deviceId)
                    ->exists()
            ) {
                $this->invalid(
                    'Apply a referral code before the first save or campaign enrolment.'
                );
            }

            $now = now();

            DB::table('reward_referrals')->insert([
                'environment' => config('vtpass.environment'),
                'inviter_device_id' => $inviter->id,
                'invited_device_id' => $deviceId,
                'attributed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'claimed' => true,
                'already_claimed' => false,
            ];

        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Qualify Invited Device
    |--------------------------------------------------------------------------
    |
    | A referral can qualify only once for a particular task.
    |
    | The first N accepted saves determine qualification.
    |
    | Later saves cannot move an old referral into another cycle.
    |
    */

    public function qualify(int $invitedDeviceId): array
    {
        $referral = DB::table('reward_referrals')
            ->where('invited_device_id', $invitedDeviceId)
            ->where('environment', config('vtpass.environment'))
            ->first();

        if (! $referral) {
            return [
                'queued' => 0,
                'processed' => 0,
            ];
        }

        $progressIds = DB::table('reward_task_progress as p')
            ->join(
                'reward_participants as rp',
                'rp.id',
                '=',
                'p.reward_participant_id'
            )
            ->join(
                'reward_campaigns as c',
                'c.id',
                '=',
                'rp.reward_campaign_id'
            )
            ->where(
                'rp.reward_device_id',
                $referral->inviter_device_id
            )
            ->where(
                'c.environment',
                config('vtpass.environment')
            )
            ->orderBy('c.id')
            ->orderBy('p.id')
            ->pluck('p.id');

        $queued = 0;

        foreach ($progressIds as $progressId) {

            $queued += DB::transaction(function () use (
                $progressId,
                $referral,
                $invitedDeviceId
            ) {

                $campaignId = DB::table('reward_task_progress as p')
                    ->join(
                        'reward_participants as rp',
                        'rp.id',
                        '=',
                        'p.reward_participant_id'
                    )
                    ->where('p.id', $progressId)
                    ->value('rp.reward_campaign_id');

                if (! $campaignId) {
                    return 0;
                }

                $campaign = DB::table('reward_campaigns')
                    ->where('id', $campaignId)
                    ->lockForUpdate()
                    ->first();

                if (! $this->campaignOpen($campaign)) {
                    return 0;
                }

                $devices = DB::table('reward_devices')
                    ->whereIn('id', [
                        $referral->inviter_device_id,
                        $invitedDeviceId,
                    ])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if (! $this->devicesEligible(
                    $devices,
                    (int) $referral->inviter_device_id,
                    $invitedDeviceId
                )) {
                    return 0;
                }

                $progress = $this->loadProgress($progressId);

                if (! $this->referralEligibleForProgress(
                    $progress,
                    $referral
                )) {
                    return 0;
                }

                $task = $this->taskSnapshot($progress);

                if (($task['type'] ?? null) !== 'referrals') {
                    return 0;
                }

                /*
                 * An invitee cannot be credited twice
                 * for the same task, even across cycles.
                 */

                if (
                    DB::table('reward_referral_credits')
                        ->where('reward_task_progress_id', $progressId)
                        ->where('reward_referral_id', $referral->id)
                        ->exists()
                ) {
                    return 0;
                }

                /*
                 * A qualification record is permanent.
                 *
                 * This includes discarded qualifications.
                 * They cannot be requeued in a later cycle.
                 */

                if (
                    DB::table('reward_referral_qualifications')
                        ->where('reward_task_progress_id', $progressId)
                        ->where('reward_referral_id', $referral->id)
                        ->exists()
                ) {
                    return 0;
                }

                /*
                 * Determine the FIRST qualification time.
                 *
                 * Do not restart the invitee's save count
                 * when a new reward cycle begins.
                 */

                $qualifiedAt = $this->qualificationTime(
                    $task,
                    $referral,
                    $progress
                );

                if (! $qualifiedAt) {
                    return 0;
                }

                /*
                 * IMPORTANT:
                 *
                 * Assign the cycle using the original
                 * qualification timestamp, not the
                 * current processing time.
                 */

                $cycle = $this->cycleForQualificationTime(
                    $progress,
                    $task,
                    $qualifiedAt
                );

                /*
                 * No eligible cycle, or a cycle that
                 * has already completed:
                 *
                 * Permanently discard the qualification.
                 *
                 * Never transfer it to another cycle.
                 */

                if (
                    ! $cycle
                    || $cycle->completed_at !== null
                    || (int) $cycle->progress_count >=
                        (int) ($task['target_count'] ?? 0)
                ) {
                    $this->storeDiscardedQualification(
                        $progressId,
                        (int) $referral->id,
                        $qualifiedAt,
                        $cycle ? (int) $cycle->id : null
                    );

                    return 0;
                }

                /*
                 * A referral qualifying during cooldown
                 * cannot count after cooldown.
                 */

                if (
                    $cycle->next_available_at !== null
                    && CarbonImmutable::parse($qualifiedAt)
                        ->lt(CarbonImmutable::parse(
                            $cycle->next_available_at
                        ))
                ) {
                    $this->storeDiscardedQualification(
                        $progressId,
                        (int) $referral->id,
                        $qualifiedAt,
                        (int) $cycle->id
                    );

                    return 0;
                }

                /*
                 * Store the qualification permanently
                 * against its original eligible cycle.
                 */

                $now = now();

                DB::table('reward_referral_qualifications')->insert([
                    'reward_task_progress_id' => $progressId,
                    'reward_task_cycle_id' => $cycle->id,
                    'reward_referral_id' => $referral->id,
                    'eligible_at' => $qualifiedAt,
                    'credited_at' => null,
                    'discarded_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return 1;

            }, 3);
        }

        return [
            'queued' => $queued,
            'processed' => $this->processPendingQualifications(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Process Pending Qualifications
    |--------------------------------------------------------------------------
    |
    | Scan pending qualifications chronologically.
    |
    | Unfunded qualifications remain pending.
    |
    | Cursor pagination prevents unfunded referrals
    | at the beginning of the queue from blocking
    | later funded campaigns.
    |
    */

    public function processPendingQualifications(int $limit = 200): int
    {
        $limit = max(1, min($limit, 1000));

        $processed = 0;

        $lastEligibleAt = null;
        $lastId = 0;

        $batchSize = 250;

        while ($processed < $limit) {

            $query = DB::table('reward_referral_qualifications')
                ->whereNull('credited_at')
                ->whereNull('discarded_at');

            if ($lastEligibleAt !== null) {

                $query->where(function ($q) use (
                    $lastEligibleAt,
                    $lastId
                ) {

                    $q->where('eligible_at', '>', $lastEligibleAt)
                        ->orWhere(function ($q) use (
                            $lastEligibleAt,
                            $lastId
                        ) {

                            $q->where(
                                'eligible_at',
                                '=',
                                $lastEligibleAt
                            )->where('id', '>', $lastId);

                        });

                });
            }

            $batch = $query
                ->orderBy('eligible_at')
                ->orderBy('id')
                ->limit($batchSize)
                ->get(['id', 'eligible_at']);

            if ($batch->isEmpty()) {
                break;
            }

            foreach ($batch as $pending) {

                /*
                 * Advance the cursor regardless
                 * of funding availability.
                 */

                $lastEligibleAt = $pending->eligible_at;
                $lastId = (int) $pending->id;

                $id = (int) $pending->id;

                $processed += DB::transaction(function () use ($id) {

                    $info = DB::table('reward_referral_qualifications as q')
                        ->join(
                            'reward_task_progress as p',
                            'p.id',
                            '=',
                            'q.reward_task_progress_id'
                        )
                        ->join(
                            'reward_participants as rp',
                            'rp.id',
                            '=',
                            'p.reward_participant_id'
                        )
                        ->where('q.id', $id)
                        ->select('rp.reward_campaign_id')
                        ->first();

                    if (! $info) {
                        return 0;
                    }

                    /*
                     * Lock the campaign first.
                     */

                    $campaign = DB::table('reward_campaigns')
                        ->where('id', $info->reward_campaign_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $this->campaignOpen($campaign)) {
                        return 0;
                    }

                    $referralId = DB::table('reward_referral_qualifications')
                        ->where('id', $id)
                        ->value('reward_referral_id');

                    $referral = DB::table('reward_referrals')
                        ->where('id', $referralId)
                        ->first();

                    if (
                        ! $referral
                        || $referral->environment !== config('vtpass.environment')
                    ) {
                        return 0;
                    }

                    $devices = DB::table('reward_devices')
                        ->whereIn('id', [
                            $referral->inviter_device_id,
                            $referral->invited_device_id,
                        ])
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    if (! $this->devicesEligible(
                        $devices,
                        (int) $referral->inviter_device_id,
                        (int) $referral->invited_device_id
                    )) {
                        return 0;
                    }

                    $queue = DB::table('reward_referral_qualifications')
                        ->where('id', $id)
                        ->lockForUpdate()
                        ->first();

                    if (
                        ! $queue
                        || $queue->credited_at !== null
                        || $queue->discarded_at !== null
                    ) {
                        return 0;
                    }

                    $progress = $this->loadProgress(
                        (int) $queue->reward_task_progress_id
                    );

                    if (! $this->referralEligibleForProgress(
                        $progress,
                        $referral
                    )) {
                        $this->discardQueue($queue->id);

                        return 0;
                    }

                    $task = $this->taskSnapshot($progress);

                    if (($task['type'] ?? null) !== 'referrals') {
                        $this->discardQueue($queue->id);

                        return 0;
                    }

                    /*
                     * Prevent duplicate referral credit.
                     */

                    if (
                        DB::table('reward_referral_credits')
                            ->where(
                                'reward_task_progress_id',
                                $progress->id
                            )
                            ->where(
                                'reward_referral_id',
                                $referral->id
                            )
                            ->exists()
                    ) {
                        $this->discardQueue($queue->id);

                        return 0;
                    }

                    /*
                     * Never transfer queued referrals
                     * from one cycle to another.
                     */

                    $cycle = $this->currentEligibleCycle(
                        $progress,
                        $task
                    );

                    if (
                        ! $cycle
                        || (int) $queue->reward_task_cycle_id
                            !== (int) $cycle->id
                    ) {
                        $this->discardQueue($queue->id);

                        return 0;
                    }

                    /*
                     * Recalculate original qualification time.
                     *
                     * Later saves must not change eligibility.
                     */

                    $qualifiedAt = $this->qualificationTime(
                        $task,
                        $referral,
                        $progress
                    );

                    if (
                        ! $qualifiedAt
                        || ! CarbonImmutable::parse($qualifiedAt)
                            ->equalTo(CarbonImmutable::parse(
                                $queue->eligible_at
                            ))
                    ) {
                        $this->discardQueue($queue->id);

                        return 0;
                    }

                    /*
                     * Reject qualifications that occurred
                     * before the cycle became available.
                     */

                    if (
                        $cycle->next_available_at !== null
                        && CarbonImmutable::parse($qualifiedAt)
                            ->lt(CarbonImmutable::parse(
                                $cycle->next_available_at
                            ))
                    ) {
                        $this->discardQueue($queue->id);

                        return 0;
                    }

                    /*
                     * Dynamic task configuration.
                     */

                    $target = (int) ($task['target_count'] ?? 0);
                    $cost = (int) ($task['max_reward_cost_kobo'] ?? 0);

                    if (
                        $target < 1
                        || $cost < 1
                        || $cycle->completed_at !== null
                        || (int) $cycle->progress_count >= $target
                    ) {
                        $this->discardQueue($queue->id);

                        return 0;
                    }

                    $now = now();

                    /*
                     * Start the cycle only after the full
                     * reward cost can be reserved.
                     */

                    if ($cycle->started_at === null) {

                        $availableBudget =
                            (int) $campaign->budget_kobo
                            - (int) $campaign->allocated_budget_kobo;

                        /*
                         * INSUFFICIENT FUNDING POLICY
                         *
                         * Keep the qualification pending.
                         *
                         * Do not:
                         * - Discard it.
                         * - Award progress.
                         * - Reserve partial funding.
                         * - Move it into another cycle.
                         *
                         * Retry when funding becomes available.
                         */

                        if ($availableBudget < $cost) {
                            return 0;
                        }

                        /*
                         * Reserve the entire reward cost
                         * atomically within this transaction.
                         */

                        DB::table('reward_campaigns')
                            ->where('id', $campaign->id)
                            ->increment(
                                'allocated_budget_kobo',
                                $cost,
                                ['updated_at' => $now]
                            );

                        DB::table('reward_participants')
                            ->where('id', $progress->participant_id)
                            ->increment(
                                'reserved_budget_kobo',
                                $cost,
                                ['updated_at' => $now]
                            );

                        DB::table('reward_task_cycles')
                            ->where('id', $cycle->id)
                            ->update([
                                'started_at' => $now,
                                'updated_at' => $now,
                            ]);

                        DB::table('reward_task_progress')
                            ->where('id', $progress->id)
                            ->update([
                                'cycle_number' => $cycle->cycle_number,
                                'progress_count' => 0,
                                'completed_at' => null,
                                'updated_at' => $now,
                            ]);
                    }

                    /*
                     * Credit one unique qualified invitee.
                     */

                    DB::table('reward_referral_credits')->insert([
                        'reward_task_progress_id' => $progress->id,
                        'reward_task_cycle_id' => $cycle->id,
                        'reward_referral_id' => $referral->id,
                        'qualified_at' => $qualifiedAt,
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
                            'cycle_number' => $cycle->cycle_number,
                            'progress_count' => $next,
                            'completed_at' => $completed ? $now : null,
                            'updated_at' => $now,
                        ]);

                    DB::table('reward_referral_qualifications')
                        ->where('id', $queue->id)
                        ->update([
                            'credited_at' => $now,
                            'discarded_at' => null,
                            'updated_at' => $now,
                        ]);

                    /*
                     * Reward completed.
                     */

                    if ($completed) {

                        DB::table('reward_entitlements')->insert([
                            'public_id' => (string) Str::uuid(),
                            'reward_task_progress_id' => $progress->id,
                            'reward_task_cycle_id' => $cycle->id,
                            'cycle_number' => $cycle->cycle_number,
                            'status' => 'earned',
                            'max_cost_kobo' => $cost,
                            'bundle_snapshot' => $progress->bundle_snapshot,
                            'earned_at' => $now,
                            'delivered_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        /*
                         * Create the next cycle for
                         * recurring referral tasks.
                         */

                        $frequencyDays = $task['frequency_days'] ?? null;

                        if ($frequencyDays !== null) {

                            $frequencyDays = (int) $frequencyDays;

                            if ($frequencyDays < 1) {
                                throw new \RuntimeException(
                                    'Invalid recurring referral frequency.'
                                );
                            }

                            DB::table('reward_task_cycles')
                                ->insertOrIgnore([
                                    'reward_task_progress_id' => $progress->id,
                                    'cycle_number' =>
                                        (int) $cycle->cycle_number + 1,
                                    'progress_count' => 0,
                                    'started_at' => null,
                                    'completed_at' => null,
                                    'next_available_at' =>
                                        CarbonImmutable::parse($now)
                                            ->addDays($frequencyDays),
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ]);
                        }

                        /*
                         * Discard remaining pending referrals
                         * belonging to the completed cycle.
                         *
                         * No carryover into the next cycle.
                         */

                        DB::table('reward_referral_qualifications')
                            ->where('reward_task_cycle_id', $cycle->id)
                            ->whereNull('credited_at')
                            ->whereNull('discarded_at')
                            ->update([
                                'discarded_at' => $now,
                                'updated_at' => $now,
                            ]);
                    }

                    return 1;

                }, 3);

                if ($processed >= $limit) {
                    break;
                }
            }
        }

        return $processed;
    }

    /*
    |--------------------------------------------------------------------------
    | Scheduled Pending Referral Retry
    |--------------------------------------------------------------------------
    */

    public function issueFundedReferralMilestones(): int
    {
        return $this->processPendingQualifications();
    }

    /*
    |--------------------------------------------------------------------------
    | Cycle Assigned by Original Qualification Time
    |--------------------------------------------------------------------------
    |
    | Historical cycles are inspected chronologically.
    |
    | A referral cannot be transferred into a later cycle
    | simply because processing was delayed.
    |
    */

    private function cycleForQualificationTime(
        object $progress,
        array $task,
        string $qualifiedAt
    ): ?object {

        $target = (int) ($task['target_count'] ?? 0);

        if ($target < 1) {
            return null;
        }

        $qualified = CarbonImmutable::parse($qualifiedAt);

        if ($qualified->isFuture()) {
            return null;
        }

        /*
         * Preserve historical successor creation.
         */

        $this->currentEligibleCycle($progress, $task);

        $cycles = DB::table('reward_task_cycles')
            ->where('reward_task_progress_id', $progress->id)
            ->orderBy('cycle_number')
            ->lockForUpdate()
            ->get();

        foreach ($cycles as $cycle) {

            /*
             * A qualification predating completion
             * belongs to that historical cycle.
             */

            if ($cycle->completed_at !== null) {

                if (
                    $qualified->lt(
                        CarbonImmutable::parse(
                            $cycle->completed_at
                        )
                    )
                ) {
                    return $cycle;
                }

                continue;
            }

            /*
             * First incomplete cycle.
             *
             * The caller checks next_available_at
             * to reject cooldown qualifications.
             */

            return $cycle;
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Current Eligible Cycle
    |--------------------------------------------------------------------------
    */

    private function currentEligibleCycle(
        object $progress,
        array $task
    ): ?object {

        $target = (int) ($task['target_count'] ?? 0);

        if ($target < 1) {
            return null;
        }

        $cycle = DB::table('reward_task_cycles')
            ->where('reward_task_progress_id', $progress->id)
            ->where('cycle_number', $progress->cycle_number)
            ->lockForUpdate()
            ->first();

        if (! $cycle) {
            throw new \RuntimeException(
                'Referral task cycle is missing.'
            );
        }

        if (
            $cycle->completed_at !== null
            || (int) $cycle->progress_count >= $target
        ) {

            $frequencyDays = $task['frequency_days'] ?? null;

            if ($frequencyDays === null) {
                return null;
            }

            $frequencyDays = (int) $frequencyDays;

            if ($frequencyDays < 1) {
                throw new \RuntimeException(
                    'Invalid recurring referral frequency.'
                );
            }

            if ($cycle->completed_at === null) {
                throw new \RuntimeException(
                    'Completed referral cycle has no completion timestamp.'
                );
            }

            $nextNumber = (int) $cycle->cycle_number + 1;

            $nextAvailableAt = CarbonImmutable::parse(
                $cycle->completed_at
            )->addDays($frequencyDays);

            DB::table('reward_task_cycles')->insertOrIgnore([
                'reward_task_progress_id' => $progress->id,
                'cycle_number' => $nextNumber,
                'progress_count' => 0,
                'started_at' => null,
                'completed_at' => null,
                'next_available_at' => $nextAvailableAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $cycle = DB::table('reward_task_cycles')
                ->where('reward_task_progress_id', $progress->id)
                ->where('cycle_number', $nextNumber)
                ->lockForUpdate()
                ->first();
        }

        if (! $cycle) {
            return null;
        }

        if (
            $cycle->completed_at !== null
            || (int) $cycle->progress_count >= $target
        ) {
            return null;
        }

        /*
         * Enforce cooldown.
         */

        if (
            $cycle->next_available_at !== null
            && CarbonImmutable::parse(
                $cycle->next_available_at
            )->isFuture()
        ) {
            return null;
        }

        return $cycle;
    }

    /*
    |--------------------------------------------------------------------------
    | First Qualification Timestamp
    |--------------------------------------------------------------------------
    |
    | Count the invitee's first N accepted saves.
    |
    | Do not restart the count when another cycle begins.
    |
    */

    private function qualificationTime(
        array $task,
        object $referral,
        object $progress
    ): ?string {

        $required = (int) (
            $task['qualification_rules']['required_unique_saves'] ?? 0
        );

        if ($required < 1) {
            return null;
        }

        $windowStart = CarbonImmutable::parse(
            $referral->attributed_at
        );

        if (
            CarbonImmutable::parse($progress->enrolled_at)
                ->greaterThan($windowStart)
        ) {
            $windowStart = CarbonImmutable::parse(
                $progress->enrolled_at
            );
        }

        $query = DB::table('reward_saved_media')
            ->where(
                'reward_device_id',
                $referral->invited_device_id
            )
            ->where('accepted_at', '>=', $windowStart);

        if (($task['media_type'] ?? 'all') !== 'all') {
            $query->where(
                'media_type',
                $task['media_type']
            );
        }

        if (($task['source'] ?? 'all') !== 'all') {
            $query->where(
                'source',
                $task['source']
            );
        }

        $nth = $query
            ->orderBy('accepted_at')
            ->orderBy('id')
            ->skip($required - 1)
            ->first(['accepted_at']);

        return $nth
            ? (string) $nth->accepted_at
            : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Load Task Progress
    |--------------------------------------------------------------------------
    */

    private function loadProgress(int $progressId): ?object
    {
        return DB::table('reward_task_progress as p')
            ->join(
                'reward_participants as rp',
                'rp.id',
                '=',
                'p.reward_participant_id'
            )
            ->where('p.id', $progressId)
            ->select(
                'p.*',
                'rp.id as participant_id',
                'rp.reward_device_id',
                'rp.enrolled_at',
                'rp.referral_device_id_floor'
            )
            ->lockForUpdate()
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Referral Eligibility
    |--------------------------------------------------------------------------
    */

    private function referralEligibleForProgress(
        ?object $progress,
        object $referral
    ): bool {

        if (! $progress) {
            return false;
        }

        if (
            (int) $progress->reward_device_id
                !== (int) $referral->inviter_device_id
        ) {
            return false;
        }

        if (
            (int) $referral->invited_device_id
                <= (int) $progress->referral_device_id_floor
        ) {
            return false;
        }

        if (
            CarbonImmutable::parse($referral->attributed_at)
                ->lt(CarbonImmutable::parse(
                    $progress->enrolled_at
                ))
        ) {
            return false;
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Task Snapshot
    |--------------------------------------------------------------------------
    */

    private function taskSnapshot(object $progress): array
    {
        return json_decode(
            $progress->task_snapshot,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Record Discarded Qualification
    |--------------------------------------------------------------------------
    */

    private function storeDiscardedQualification(
        int $progressId,
        int $referralId,
        string $qualifiedAt,
        ?int $cycleId
    ): void {

        $now = now();

        DB::table('reward_referral_qualifications')
            ->insertOrIgnore([
                'reward_task_progress_id' => $progressId,
                'reward_task_cycle_id' => $cycleId,
                'reward_referral_id' => $referralId,
                'eligible_at' => $qualifiedAt,
                'credited_at' => null,
                'discarded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Discard Existing Queue Entry
    |--------------------------------------------------------------------------
    */

    private function discardQueue(int $id): void
    {
        DB::table('reward_referral_qualifications')
            ->where('id', $id)
            ->whereNull('credited_at')
            ->whereNull('discarded_at')
            ->update([
                'discarded_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Campaign Availability
    |--------------------------------------------------------------------------
    */

    private function campaignOpen(?object $campaign): bool
    {
        if (
            ! $campaign
            || $campaign->environment !== config('vtpass.environment')
            || ! in_array(
                $campaign->status,
                ['active', 'paused'],
                true
            )
        ) {
            return false;
        }

        $now = now();

        if (
            $campaign->starts_at
            && CarbonImmutable::parse(
                $campaign->starts_at
            )->greaterThan($now)
        ) {
            return false;
        }

        if (
            $campaign->tasks_close_at
            && CarbonImmutable::parse(
                $campaign->tasks_close_at
            )->lessThanOrEqualTo($now)
        ) {
            return false;
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Device Eligibility
    |--------------------------------------------------------------------------
    */

    private function devicesEligible(
        $devices,
        int $inviterId,
        int $invitedId
    ): bool {

        $inviter = $devices->get($inviterId);
        $invited = $devices->get($invitedId);

        return $inviter
            && $invited
            && $inviter->blocked_at === null
            && $invited->blocked_at === null
            && $inviter->archived_at === null
            && $invited->archived_at === null;
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages([
            'referral_code' => $message,
        ]);
    }
}