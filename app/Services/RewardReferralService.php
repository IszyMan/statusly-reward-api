<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RewardReferralService
{
    
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

            abort_unless($device && $inviter, 404, 'Device unavailable.');

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

            $hasSaved = DB::table('reward_saved_media')
                ->where('reward_device_id', $deviceId)
                ->exists();

            $hasEnrolled = DB::table('reward_participants')
                ->where('reward_device_id', $deviceId)
                ->exists();

            if ($hasSaved || $hasEnrolled) {
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

    /**
     * Find newly qualified referrals and add them to the durable queue.
     * Existing queue entries are protected by a database unique constraint.
     */
    public function qualify(int $invitedDeviceId): array
    {
        $queued = DB::transaction(function () use ($invitedDeviceId) {
            $referral = DB::table('reward_referrals')
                ->where('invited_device_id', $invitedDeviceId)
                ->where('environment', config('vtpass.environment'))
                ->first();

            if (! $referral) {
                return [];
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

            $campaigns = DB::table('reward_campaigns')
                ->where('environment', config('vtpass.environment'))
                ->whereIn(
                    'id',
                    DB::table('reward_participants')
                        ->where('reward_device_id', $referral->inviter_device_id)
                        ->select('reward_campaign_id')
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            

            
            $inviter = $devices->get($referral->inviter_device_id);
            $invited = $devices->get($invitedDeviceId);

            if (
                ! $inviter || ! $invited
                || $inviter->blocked_at
                || $invited->blocked_at
                || $inviter->archived_at
                || $invited->archived_at
            ) {
                return [];
            }


            $progressRows = DB::table('reward_task_progress as progress')
                ->join(
                    'reward_participants as participants',
                    'participants.id',
                    '=',
                    'progress.reward_participant_id'
                )
                ->where(
                    'participants.reward_device_id',
                    $referral->inviter_device_id
                )
                ->whereIn(
                    'participants.reward_campaign_id',
                    $campaigns->keys()->all()
                )
                ->select([
                    'progress.*',
                    'participants.reward_campaign_id',
                    'participants.enrolled_at',
                    'participants.referral_device_id_floor',
                ])
                ->orderBy('progress.id')
                ->lockForUpdate()
                ->get();

            $now = now();
            $queued = [];

            foreach ($progressRows as $progress) {
                $campaign = $campaigns->get(
                    $progress->reward_campaign_id
                );

                if (! $campaign) {
                    continue;
                }

                if (! in_array($campaign->status, ['active', 'paused'], true)) {
                    continue;
                }

                if (
                    $campaign->starts_at
                    && CarbonImmutable::parse($campaign->starts_at)->greaterThan($now)
                ) {
                    continue;
                }

                if (
                    $campaign->tasks_close_at
                    && CarbonImmutable::parse($campaign->tasks_close_at)
                        ->lessThanOrEqualTo($now)
                ) {
                    continue;
                }

                if (
                    $invitedDeviceId
                    <= (int) $progress->referral_device_id_floor
                ) {
                    continue;
                }

                if (
                    CarbonImmutable::parse($referral->attributed_at)
                        ->lessThan(CarbonImmutable::parse($progress->enrolled_at))
                ) {
                    continue;
                }

                $task = json_decode(
                    $progress->task_snapshot,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                if (($task['type'] ?? null) !== 'referrals') {
                    continue;
                }

                $requiredSaves = (int) (
                    $task['qualification_rules']['required_unique_saves'] ?? 0
                );

                if ($requiredSaves < 1) {
                    continue;
                }

                $alreadyCredited = DB::table('reward_referral_credits')
                    ->where('reward_task_progress_id', $progress->id)
                    ->where('reward_referral_id', $referral->id)
                    ->exists();

                if ($alreadyCredited) {
                    continue;
                }

                $saves = DB::table('reward_saved_media')
                    ->where('reward_device_id', $invitedDeviceId)
                    ->where('accepted_at', '>=', $referral->attributed_at)
                    ->where('accepted_at', '>=', $progress->enrolled_at);

                if (($task['media_type'] ?? 'all') !== 'all') {
                    $saves->where('media_type', $task['media_type']);
                }

                if (($task['source'] ?? 'all') !== 'all') {
                    $saves->where('source', $task['source']);
                }

                if ($saves->count() < $requiredSaves) {
                    continue;
                }

                $alreadyQueued = DB::table('reward_referral_qualifications')
                    ->where('reward_task_progress_id', $progress->id)
                    ->where('reward_referral_id', $referral->id)
                    ->exists();

                if ($alreadyQueued) {
                    continue;
                }

                DB::table('reward_referral_qualifications')->insert([
                    'reward_task_progress_id' => $progress->id,
                    'reward_referral_id' => $referral->id,
                    'eligible_at' => $now,
                    'credited_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $queued[] = [
                    'progress_id' => $progress->id,
                    'referral_id' => $referral->id,
                ];
            }

            return $queued;
        }, 3);

        // Try to process eligible referrals immediately.
        // The scheduled command will retry any that remain queued.
        $credited = $this->processPendingQualifications();

        return [
            'queued' => count($queued),
            'processed' => $credited,
        ];
    }

    /**
     * Process queued referrals in oldest-first order.
     * Returns the number of queue entries credited during this run.
     */
    
    public function processPendingQualifications(int $limit = 200): int
    {
        $ids = DB::table('reward_referral_qualifications')
            ->whereNull('credited_at')
            ->orderBy('eligible_at')
            ->orderBy('id')
            ->limit(max(1, min($limit, 1000)))
            ->pluck('id');

        $processed = 0;

        foreach ($ids as $id) {
            $wasCredited = DB::transaction(function () use ($id) {
                $queue = DB::table('reward_referral_qualifications')
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (! $queue || $queue->credited_at !== null) {
                    return false;
                }

                $referral = DB::table('reward_referrals')
                    ->where('id', $queue->reward_referral_id)
                    ->first();

                if (
                    ! $referral
                    || $referral->environment !== config('vtpass.environment')
                ) {
                    return false;
                }

                $progressInfo = DB::table('reward_task_progress as progress')
                    ->join(
                        'reward_participants as participants',
                        'participants.id',
                        '=',
                        'progress.reward_participant_id'
                    )
                    ->where('progress.id', $queue->reward_task_progress_id)
                    ->select([
                        'progress.*',
                        'participants.id as participant_id',
                        'participants.reward_campaign_id',
                        'participants.reward_device_id',
                        'participants.enrolled_at',
                        'participants.referral_device_id_floor',
                    ])
                    ->first();

                if (! $progressInfo) {
                    return false;
                }

                if (
                    (int) $progressInfo->reward_device_id
                    !== (int) $referral->inviter_device_id
                    || (int) $referral->invited_device_id
                    <= (int) $progressInfo->referral_device_id_floor
                    || CarbonImmutable::parse($referral->attributed_at)
                        ->lessThan(
                            CarbonImmutable::parse($progressInfo->enrolled_at)
                        )
                ) {
                    return false;
                }

                /*
                * Lock the inviter device first. This serializes processing
                * for referrals belonging to the same inviter, including
                * referrals being processed for different campaigns.
                */
                $devices = DB::table('reward_devices')
                    ->whereIn('id', [
                        $referral->inviter_device_id,
                        $referral->invited_device_id,
                    ])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $inviter = $devices->get($referral->inviter_device_id);
                $invited = $devices->get($referral->invited_device_id);

                if (
                    ! $inviter || ! $invited
                    || $inviter->blocked_at
                    || $invited->blocked_at
                    || $inviter->archived_at
                    || $invited->archived_at
                ) {
                    return false;
                }

                $campaign = DB::table('reward_campaigns')
                    ->where('id', $progressInfo->reward_campaign_id)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $campaign
                    || $campaign->environment !== config('vtpass.environment')
                    || ! in_array($campaign->status, ['active', 'paused'], true)
                ) {
                    return false;
                }

                $now = now();

                if (
                    $campaign->starts_at
                    && CarbonImmutable::parse($campaign->starts_at)
                        ->greaterThan($now)
                ) {
                    return false;
                }

                if (
                    $campaign->tasks_close_at
                    && CarbonImmutable::parse($campaign->tasks_close_at)
                        ->lessThanOrEqualTo($now)
                ) {
                    return false;
                }

                $task = json_decode(
                    $progressInfo->task_snapshot,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                if (($task['type'] ?? null) !== 'referrals') {
                    return false;
                }

                $alreadyCredited = DB::table('reward_referral_credits')
                    ->where(
                        'reward_task_progress_id',
                        $progressInfo->id
                    )
                    ->where('reward_referral_id', $referral->id)
                    ->exists();

                if ($alreadyCredited) {
                    DB::table('reward_referral_qualifications')
                        ->where('id', $queue->id)
                        ->update([
                            'credited_at' => $now,
                            'updated_at' => $now,
                        ]);

                    return false;
                }

                $period = $campaign->referral_credit_period ?? 'daily';
                $creditLimit = (int) ($campaign->referral_credit_limit ?? 15);

                if (! in_array($period, ['daily', 'weekly'], true)) {
                    $period = 'daily';
                }

                if ($creditLimit < 1) {
                    $creditLimit = 15;
                }

                $periodStart = CarbonImmutable::now('Africa/Lagos');

                if ($period === 'weekly') {
                    $periodStart = $periodStart->startOfWeek(
                        \Carbon\CarbonInterface::MONDAY
                    );
                } else {
                    $periodStart = $periodStart->startOfDay();
                }

                $periodEnd = $period === 'weekly'
                    ? $periodStart->addWeek()
                    : $periodStart->addDay();

                $sameReferralCreditedInPeriod = DB::table(
                    'reward_referral_qualifications as qualifications'
                )
                    ->join(
                        'reward_task_progress as progress',
                        'progress.id',
                        '=',
                        'qualifications.reward_task_progress_id'
                    )
                    ->join(
                        'reward_participants as participants',
                        'participants.id',
                        '=',
                        'progress.reward_participant_id'
                    )
                    ->where(
                        'participants.reward_device_id',
                        $referral->inviter_device_id
                    )
                    ->where(
                        'participants.reward_campaign_id',
                        $campaign->id
                    )
                    ->where(
                        'qualifications.reward_referral_id',
                        $referral->id
                    )
                    ->whereNotNull('qualifications.credited_at')
                    ->where('qualifications.credited_at', '>=', $periodStart)
                    ->where('qualifications.credited_at', '<', $periodEnd)
                    ->exists();

                if (! $sameReferralCreditedInPeriod) {
                    $periodCount = DB::table(
                        'reward_task_progress as progress'
                    )
                        ->join(
                            'reward_participants as participants',
                            'participants.id',
                            '=',
                            'progress.reward_participant_id'
                        )
                        ->join(
                            'reward_referral_qualifications as qualifications',
                            'qualifications.reward_task_progress_id',
                            '=',
                            'progress.id'
                        )
                        ->where(
                            'participants.reward_device_id',
                            $referral->inviter_device_id
                        )
                        ->where(
                            'participants.reward_campaign_id',
                            $campaign->id
                        )
                        ->whereNotNull('qualifications.credited_at')
                        ->where('qualifications.credited_at', '>=', $periodStart)
                        ->where('qualifications.credited_at', '<', $periodEnd)
                        ->distinct()
                        ->count('qualifications.reward_referral_id');

                    if ($periodCount >= $creditLimit) {
                        // Keep this referral queued for a later processing run.
                        return false;
                    }
                }
                $now = now();

                DB::table('reward_referral_credits')->insert([
                    'reward_task_progress_id' => $progressInfo->id,
                    'reward_referral_id' => $referral->id,
                    'qualified_at' => $now,
                ]);

                DB::table('reward_task_progress')
                    ->where('id', $progressInfo->id)
                    ->update([
                        'progress_count' => DB::raw('progress_count + 1'),
                        'updated_at' => $now,
                    ]);

                DB::table('reward_referral_qualifications')
                    ->where('id', $queue->id)
                    ->update([
                        'credited_at' => $now,
                        'updated_at' => $now,
                    ]);

                return true;
            }, 3);

            if ($wasCredited) {
                $processed++;
            }
        }

        // Retry milestones that were earned but could not previously be funded.
        $this->issueFundedReferralMilestones();

        return $processed;
    }


    /**
     * Issue any milestones earned by credited progress, provided the
     * campaign has sufficient unallocated budget.
     */
    
    public function issueFundedReferralMilestones(): int
    {
        $issued = 0;

        $rows = DB::table('reward_task_progress as progress')
            ->join(
                'reward_participants as participants',
                'participants.id',
                '=',
                'progress.reward_participant_id'
            )
            ->join(
                'reward_campaigns as campaigns',
                'campaigns.id',
                '=',
                'participants.reward_campaign_id'
            )
            ->where('campaigns.environment', config('vtpass.environment'))
            ->whereIn('campaigns.status', ['active', 'paused'])
            ->orderBy('campaigns.id')
            ->orderBy('progress.id')
            ->select([
                'progress.id as progress_id',
                'participants.id as participant_id',
                'participants.reward_campaign_id',
            ])
            ->get();

        foreach ($rows as $row) {
            $issued += DB::transaction(function () use ($row) {
                $now = now();

                $campaign = DB::table('reward_campaigns')
                    ->where('id', $row->reward_campaign_id)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $campaign
                    || $campaign->environment !== config('vtpass.environment')
                    || ! in_array($campaign->status, ['active', 'paused'], true)
                ) {
                    return 0;
                }

                if (
                    $campaign->starts_at
                    && CarbonImmutable::parse($campaign->starts_at)
                        ->greaterThan($now)
                ) {
                    return 0;
                }

                if (
                    $campaign->tasks_close_at
                    && CarbonImmutable::parse($campaign->tasks_close_at)
                        ->lessThanOrEqualTo($now)
                ) {
                    return 0;
                }

                $progress = DB::table('reward_task_progress')
                    ->where('id', $row->progress_id)
                    ->lockForUpdate()
                    ->first();

                if (! $progress) {
                    return 0;
                }

                $participant = DB::table('reward_participants')
                    ->where('id', $row->participant_id)
                    ->lockForUpdate()
                    ->first();

                if (! $participant) {
                    return 0;
                }

                $task = json_decode(
                    $progress->task_snapshot,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                if (($task['type'] ?? null) !== 'referrals') {
                    return 0;
                }

                $interval = (int) ($task['target_count'] ?? 0);
                $maxCost = (int) ($task['max_reward_cost_kobo'] ?? 0);

                if ($interval < 1 || $maxCost < 1) {
                    return 0;
                }

                $milestonesEarned = intdiv(
                    (int) $progress->progress_count,
                    $interval
                );

                $existingRewards = DB::table('reward_entitlements')
                    ->where('reward_task_progress_id', $progress->id)
                    ->count();

                $newRewards = 0;

                while ($existingRewards < $milestonesEarned) {
                    /*
                    * The initial reward was reserved when the participant
                    * enrolled. Each subsequent reward needs a new reservation.
                    */
                    if ($existingRewards > 0) {
                        $available = (int) $campaign->budget_kobo
                            - (int) $campaign->allocated_budget_kobo;

                        if ($available < $maxCost) {
                            break;
                        }

                        DB::table('reward_campaigns')
                            ->where('id', $campaign->id)
                            ->increment('allocated_budget_kobo', $maxCost);

                        DB::table('reward_participants')
                            ->where('id', $participant->id)
                            ->increment('reserved_budget_kobo', $maxCost);

                        $campaign->allocated_budget_kobo =
                            (int) $campaign->allocated_budget_kobo + $maxCost;

                        $participant->reserved_budget_kobo =
                            (int) $participant->reserved_budget_kobo + $maxCost;
                    }

                    $now = now();

                    DB::table('reward_entitlements')->insert([
                        'public_id' => (string) Str::uuid(),
                        'reward_task_progress_id' => $progress->id,
                        'reward_task_cycle_id' => null,
                        'cycle_number' => 1,
                        'status' => 'earned',
                        'max_cost_kobo' => $maxCost,
                        'bundle_snapshot' => $progress->bundle_snapshot,
                        'earned_at' => $now,
                        'delivered_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $existingRewards++;
                    $newRewards++;
                }

                return $newRewards;
            }, 3);
        }

        return $issued;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages([
            'referral_code' => $message,
        ]);
    }
}
