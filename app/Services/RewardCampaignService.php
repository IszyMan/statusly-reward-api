<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RewardCampaignService
{
    public function activate(int $campaignId): void
    {
        // Activation is restricted to our current local testing phase.
        abort_unless(
            app()->environment('local', 'testing')
                && config('vtpass.environment') === 'sandbox',
            403,
            'Campaign activation currently supports local sandbox testing.'
        );

        DB::transaction(function () use ($campaignId) {
            $campaign = DB::table('reward_campaigns')
                ->where('id', $campaignId)
                ->lockForUpdate()
                ->first();

            abort_unless($campaign, 404, 'Campaign not found.');

            if ($campaign->environment !== 'sandbox') {
                $this->invalid('Only sandbox campaigns can be activated here.');
            }

            if ($campaign->status === 'active') {
                return;
            }

            if ($campaign->status !== 'draft') {
                $this->invalid('Only draft campaigns can be activated here.');
            }

            if (
                $campaign->enrollment_closes_at
                && CarbonImmutable::parse(
                    $campaign->enrollment_closes_at
                )->isPast()
            ) {
                $this->invalid('The enrolment deadline has already passed.');
            }

            if (
                $campaign->tasks_close_at
                && CarbonImmutable::parse(
                    $campaign->tasks_close_at
                )->isPast()
            ) {
                $this->invalid('The task deadline has already passed.');
            }

            $tasks = $this->taskSnapshots($campaign->id);
            $reservation = array_sum(
                array_column($tasks, 'max_reward_cost_kobo')
            );

            $limit = (int) $campaign->participant_limit;
            $budget = (int) $campaign->budget_kobo;

            if (
                $limit < 1
                || $reservation < 1
                || $reservation > intdiv($budget, $limit)
            ) {
                $this->invalid(
                    'The budget does not cover the participant limit.'
                );
            }

            DB::table('reward_campaigns')
                ->where('id', $campaign->id)
                ->update([
                    'status' => 'active',
                    'updated_at' => now(),
                ]);
        }, 3);
    }

    public function join(string $campaignCode, int $deviceId): array
    {
        return DB::transaction(function () use (
            $campaignCode,
            $deviceId
        ) {
            // Lock the campaign before checking capacity or budget.
            $campaign = DB::table('reward_campaigns')
                ->where('code', $campaignCode)
                ->where('environment', config('vtpass.environment'))
                ->lockForUpdate()
                ->first();

            abort_unless($campaign, 404, 'Campaign not found.');

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

            $existing = DB::table('reward_participants')
                ->where('reward_campaign_id', $campaign->id)
                ->where('reward_device_id', $deviceId)
                ->first();

            // A repeated request must not reset progress or reserve again.
            if ($existing) {
                return [
                    'participant_id' => $existing->id,
                    'campaign_code' => $campaign->code,
                    'already_joined' => true,
                ];
            }

            if ($campaign->status !== 'active') {
                $this->invalid('This campaign is not accepting enrolments.');
            }

            $now = now();

            if (
                $campaign->starts_at
                && CarbonImmutable::parse(
                    $campaign->starts_at
                )->isFuture()
            ) {
                $this->invalid('This campaign has not started yet.');
            }

            if (
                $campaign->enrollment_closes_at
                && CarbonImmutable::parse(
                    $campaign->enrollment_closes_at
                )->lessThanOrEqualTo($now)
            ) {
                $this->invalid('Enrolment has closed.');
            }

            if (
                $campaign->tasks_close_at
                && CarbonImmutable::parse(
                    $campaign->tasks_close_at
                )->lessThanOrEqualTo($now)
            ) {
                $this->invalid('This campaign has ended.');
            }

            // "New users" currently means device profiles first registered
            // after this campaign was created.
            if (
                $campaign->audience === 'new_devices'
                && CarbonImmutable::parse(
                    $device->first_seen_at
                )->lessThan(
                    CarbonImmutable::parse($campaign->created_at)
                )
            ) {
                $this->invalid(
                    'This campaign is for newly registered devices.'
                );
            }

            if (
                (int) $campaign->enrolled_count
                >= (int) $campaign->participant_limit
            ) {
                $this->invalid('All campaign places are currently reserved.');
            }

            $tasks = $this->taskSnapshots($campaign->id);

            $reservation = array_sum(
                array_column($tasks, 'max_reward_cost_kobo')
            );

            $availableBudget = (int) $campaign->budget_kobo
                - (int) $campaign->allocated_budget_kobo;

            if ($reservation > $availableBudget) {
                $this->invalid(
                    'This campaign has no remaining reward budget.'
                );
            }

            $participantId = DB::table('reward_participants')
                ->insertGetId([
                    'reward_campaign_id' => $campaign->id,
                    'reward_device_id' => $deviceId,
                    'referral_device_id_floor' => (int) (
                        DB::table('reward_devices')->max('id') ?? 0
                    ),
                    'reserved_budget_kobo' => $reservation,
                    'enrolled_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                    
                ]);

            foreach ($tasks as $task) {
                $bundles = $task['bundles'];
                unset($task['bundles']);

                $progressId = DB::table('reward_task_progress')->insertGetId([
                    'reward_participant_id' => $participantId,
                    'reward_campaign_task_id' => $task['id'],
                    'progress_count' => 0,
                    'cycle_number' => 1,
                    'task_snapshot' => json_encode(
                        $task,
                        JSON_THROW_ON_ERROR
                    ),
                    'bundle_snapshot' => json_encode(
                        $bundles,
                        JSON_THROW_ON_ERROR
                    ),
                    'completed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('reward_task_cycles')->insert([
                    'reward_task_progress_id' => $progressId,
                    'cycle_number' => 1,
                    'progress_count' => 0,
                    'started_at' => $now,
                    'completed_at' => null,
                    'next_available_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('reward_campaigns')
                ->where('id', $campaign->id)
                ->update([
                    'enrolled_count' => (
                        (int) $campaign->enrolled_count
                    ) + 1,

                    'allocated_budget_kobo' => (
                        (int) $campaign->allocated_budget_kobo
                    ) + $reservation,

                    'updated_at' => $now,
                ]);

            return [
                'participant_id' => $participantId,
                'campaign_code' => $campaign->code,
                'already_joined' => false,
            ];
        }, 3);
    }

    public function taskSnapshots(int $campaignId): array
    {
        $tasks = DB::table('reward_campaign_tasks')
            ->where('reward_campaign_id', $campaignId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($tasks->isEmpty()) {
            $this->invalid('The campaign has no tasks.');
        }

        $result = [];

        foreach ($tasks as $task) {
            if (
                ! in_array($task->type, ['save_media', 'referrals'], true)
                || (int) $task->target_count < 1
                || (int) $task->max_reward_cost_kobo < 1
            ) {
                $this->invalid('The campaign contains an invalid task.');
            }

            $rules = $task->qualification_rules
                ? json_decode(
                    $task->qualification_rules,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                )
                : [];

            if (
                $task->type === 'referrals'
                && (int) ($rules['required_unique_saves'] ?? 0) < 1
            ) {
                $this->invalid(
                    'Referral tasks need a qualifying save requirement.'
                );
            }

            $bundles = DB::table('reward_task_bundles')
                ->where('reward_campaign_task_id', $task->id)
                ->where('enabled', true)
                ->orderBy('network')
                ->get();

            if ($bundles->isEmpty()) {
                $this->invalid('Each task must offer at least one bundle.');
            }

            $offers = [];

            foreach ($bundles as $bundle) {
                if (
                    (int) $bundle->listed_price_kobo < 1
                    || (int) $bundle->listed_price_kobo
                        > (int) $task->max_reward_cost_kobo
                ) {
                    $this->invalid(
                        'A bundle exceeds its task reward cost limit.'
                    );
                }

                $offers[] = [
                    'network' => $bundle->network,
                    'service_id' => $bundle->service_id,
                    'variation_code' => $bundle->variation_code,
                    'bundle_name' => $bundle->bundle_name,
                    'validity_label' => $bundle->validity_label,
                    'data_amount_mb' => $bundle->data_amount_mb,
                    'listed_price_kobo' => (int) $bundle->listed_price_kobo,
                ];
            }

            $result[] = [
                'id' => (int) $task->id,
                'title' => $task->title,
                'type' => $task->type,
                'target_count' => (int) $task->target_count,
                'frequency_days' => $task->type === 'save_media'
                    ? (
                        $task->frequency_days === null
                            ? null
                            : (int) $task->frequency_days
                    )
                    : null,
                'media_type' => $task->media_type,
                'source' => $task->source,
                'qualification_rules' => $rules,
                'max_reward_cost_kobo' => (int) $task->max_reward_cost_kobo,
                'bundles' => $offers,
            ];
        }

        return $result;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages([
            'campaign' => $message,
        ]);
    }
}