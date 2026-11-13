<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RewardCampaignService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RewardCampaignController extends Controller
{
    public function index(
        Request $request,
        RewardCampaignService $service
    ) {
        $campaigns = DB::table('reward_campaigns')
            ->where('environment', config('vtpass.environment'))
            ->where('status', 'active')
            ->where('show_in_app', true)
            ->orderByDesc('id')
            ->paginate(10);

        $items = [];

        foreach ($campaigns as $campaign) {
            $items[] = [
                'code' => $campaign->code,
                'name' => $campaign->name,
                'description' => $campaign->description,
                'terms' => $campaign->terms,
                'audience' => $campaign->audience,
                'starts_at' => $campaign->starts_at,
                'enrollment_closes_at' => $campaign->enrollment_closes_at,
                'tasks_close_at' => $campaign->tasks_close_at,
                'tasks' => $service->taskSnapshots($campaign->id),
            ];
        }

        return response()->json([
            'environment' => config('vtpass.environment'),
            'campaigns' => $items,
            'current_page' => $campaigns->currentPage(),
            'last_page' => $campaigns->lastPage(),
            'saved_announcements' => $this->savedAnnouncements(
                (int) $request->attributes->get('reward_device')->id
            ),
        ]);
    }

    public function join(
        Request $request,
        string $campaignCode,
        RewardCampaignService $service
    ) {
        $device = $request->attributes->get('reward_device');

        $result = $service->join($campaignCode, $device->id);

        return response()->json($result);
    }

    public function enrolments(Request $request)
    {
        $device = $request->attributes->get('reward_device');
        $now = CarbonImmutable::now();

        /*
         * 1. Retrieve campaign enrolments.
         *
         * Include budget information so the API can distinguish
         * available recurring cycles from unfunded cycles.
         */
        $participants = DB::table('reward_participants as participants')
            ->join(
                'reward_campaigns as campaigns',
                'campaigns.id',
                '=',
                'participants.reward_campaign_id'
            )
            ->where('participants.reward_device_id', $device->id)
            ->where(
                'campaigns.environment',
                config('vtpass.environment')
            )
            ->select([
                'participants.id',
                'participants.enrolled_at',
                'campaigns.id as campaign_id',
                'campaigns.code',
                'campaigns.name',
                'campaigns.status',
                'campaigns.starts_at',
                'campaigns.tasks_close_at',
                'campaigns.budget_kobo',
                'campaigns.allocated_budget_kobo',
            ])
            ->orderByDesc('participants.id')
            ->get();

        if ($participants->isEmpty()) {
            return response()->json([
                'enrolments' => [],
            ]);
        }

        $participantsById = $participants->keyBy('id');

        /*
         * 2. Retrieve task progress.
         *
         * cycle_number identifies the active or most recently
         * completed cycle.
         */
        $progressRows = DB::table('reward_task_progress')
            ->whereIn(
                'reward_participant_id',
                $participants->pluck('id')
            )
            ->orderBy('id')
            ->get([
                'id',
                'reward_participant_id',
                'cycle_number',
                'progress_count',
                'task_snapshot',
                'bundle_snapshot',
                'completed_at',
            ]);

        $progressByParticipant = $progressRows->groupBy(
            'reward_participant_id'
        );

        /*
         * 3. Retrieve cycle records.
         *
         * Fetch all cycles in one query to avoid querying
         * the database separately for each task.
         */
        $cycles = collect();

        if ($progressRows->isNotEmpty()) {
            $cycles = DB::table('reward_task_cycles')
                ->whereIn(
                    'reward_task_progress_id',
                    $progressRows->pluck('id')
                )
                ->orderBy('reward_task_progress_id')
                ->orderBy('cycle_number')
                ->get([
                    'id',
                    'reward_task_progress_id',
                    'cycle_number',
                    'progress_count',
                    'started_at',
                    'completed_at',
                    'next_available_at',
                ]);
        }

        $cyclesByProgress = $cycles->groupBy(
            'reward_task_progress_id'
        );

        /*
         * 4. Retrieve earned reward entitlements.
         */
        $rewards = collect();

        if ($progressRows->isNotEmpty()) {
            $rewards = DB::table('reward_entitlements')
                ->whereIn(
                    'reward_task_progress_id',
                    $progressRows->pluck('id')
                )
                ->orderBy('id')
                ->get([
                    'id',
                    'reward_task_progress_id',
                    'reward_task_cycle_id',
                    'cycle_number',
                    'public_id',
                    'status',
                    'max_cost_kobo',
                    'earned_at',
                    'delivered_at',
                ]);
        }

        $rewardsByProgress = $rewards->groupBy(
            'reward_task_progress_id'
        );

        /*
         * 5. Retrieve redemption details.
         */
        $redemptions = collect();

        if ($rewards->isNotEmpty()) {
            $redemptions = DB::table('reward_redemptions')
                ->whereIn(
                    'reward_entitlement_id',
                    $rewards->pluck('id')
                )
                ->orderBy('id')
                ->get([
                    'reward_entitlement_id',
                    'network',
                    'bundle_snapshot',
                    'status',
                    'submitted_at',
                    'delivered_at',
                ]);
        }

        $redemptionsByReward = $redemptions
            ->groupBy('reward_entitlement_id')
            ->map(fn ($rows) => $rows->first());

        /*
         * 6. Assemble enrolments.
         *
         * Preserve original Flutter response fields.
         * Add cycle information without removing existing data.
         */
        $items = [];

        foreach ($participants as $participant) {
            $tasks = [];

            $participantProgress = $progressByParticipant->get(
                $participant->id,
                collect()
            );

            foreach ($participantProgress as $progress) {
                $task = json_decode(
                    $progress->task_snapshot,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                $bundles = json_decode(
                    $progress->bundle_snapshot,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                $frequencyDays = $task['frequency_days'] ?? null;

                $isRecurring = $frequencyDays !== null
                    && (int) $frequencyDays > 0;

                $target = (int) ($task['target_count'] ?? 0);

                $taskCycles = $cyclesByProgress->get(
                    $progress->id,
                    collect()
                );

                $currentCycleNumber = (int) $progress->cycle_number;

                $currentCycle = $taskCycles->firstWhere(
                    'cycle_number',
                    $currentCycleNumber
                );

                $nextCycle = $isRecurring
                    ? $taskCycles->firstWhere(
                        'cycle_number',
                        $currentCycleNumber + 1
                    )
                    : null;

                /*
                 * Resolve current and next cycle states.
                 */
                $cycleStatus = 'started';
                $displayCycleNumber = $currentCycleNumber;
                $displayProgressCount = (int) $progress->progress_count;
                $displayCompletedAt = $progress->completed_at;

                $cycleStartedAt = $currentCycle?->started_at;
                $cycleCompletedAt = $currentCycle?->completed_at;
                $nextAvailableAt = null;

                $nextCycleData = null;

                $availableBudget = max(
                    0,
                    (int) $participant->budget_kobo
                        - (int) $participant->allocated_budget_kobo
                );

                $rewardCost = (int) (
                    $task['max_reward_cost_kobo'] ?? 0
                );

                $campaignAcceptsActivity = in_array(
                    $participant->status,
                    ['active', 'paused'],
                    true
                );

                if (
                    $participant->starts_at !== null
                    && CarbonImmutable::parse(
                        $participant->starts_at
                    )->greaterThan($now)
                ) {
                    $campaignAcceptsActivity = false;
                }

                if (
                    $participant->tasks_close_at !== null
                    && CarbonImmutable::parse(
                        $participant->tasks_close_at
                    )->lessThanOrEqualTo($now)
                ) {
                    $campaignAcceptsActivity = false;
                }

                /*
                 * Completed current cycle.
                 */
                if ($currentCycle?->completed_at !== null) {
                    $cycleStatus = 'completed';

                    /*
                     * For recurring tasks, the successor is scheduled
                     * immediately when the previous cycle completes.
                     */
                    if ($isRecurring) {
                        $nextAvailableAt = $nextCycle?->next_available_at;

                        /*
                         * Compatibility for older completed cycles
                         * whose successor has not yet been inserted.
                         */
                        if ($nextAvailableAt === null) {
                            $nextAvailableAt = CarbonImmutable::parse(
                                $currentCycle->completed_at
                            )->addDays(
                                (int) $frequencyDays
                            )->toDateTimeString();
                        }

                        $cooldownActive = CarbonImmutable::parse(
                            $nextAvailableAt
                        )->greaterThan($now);

                        if ($cooldownActive) {
                            $nextStatus = 'cooldown';
                        } elseif (
                            $rewardCost > $availableBudget
                        ) {
                            $nextStatus = 'waiting_for_reward';
                        } else {
                            $nextStatus = 'available';
                        }

                        /*
                         * A campaign outside its activity window
                         * cannot currently start a new cycle.
                         */
                        if (
                            ! $cooldownActive
                            && ! $campaignAcceptsActivity
                        ) {
                            $nextStatus = 'unavailable';
                        }

                        $nextCycleData = [
                            'cycle_number' => $currentCycleNumber + 1,
                            'progress_count' => 0,
                            'target_count' => $target,
                            'status' => $nextStatus,
                            'started_at' => null,
                            'completed_at' => null,
                            'next_available_at' => $nextAvailableAt,
                        ];

                        /*
                         * The task-level state describes what
                         * the user can do next.
                         *
                         * Historical cycle records still show
                         * the previous cycle as completed.
                         */
                        $cycleStatus = $nextStatus;
                        $displayCycleNumber = $currentCycleNumber + 1;
                        $displayProgressCount = 0;
                        $displayCompletedAt = null;
                        $cycleStartedAt = null;
                        $cycleCompletedAt = null;
                    }
                } elseif ($currentCycle !== null) {
                    /*
                     * Active cycle, including the first cycle.
                     */
                    $cycleStatus = 'started';

                    $cycleStartedAt = $currentCycle->started_at;
                    $cycleCompletedAt = $currentCycle->completed_at;
                    $nextAvailableAt = $currentCycle->next_available_at;
                }

                /*
                 * Build historical cycle records.
                 */
                $cycleHistory = $taskCycles->map(function ($cycle) use (
                    $target,
                    $now
                ) {
                    if ($cycle->completed_at !== null) {
                        $status = 'completed';
                    } elseif (
                        $cycle->started_at !== null
                    ) {
                        $status = 'started';
                    } elseif (
                        $cycle->next_available_at !== null
                        && CarbonImmutable::parse(
                            $cycle->next_available_at
                        )->greaterThan($now)
                    ) {
                        $status = 'cooldown';
                    } else {
                        $status = 'pending';
                    }

                    return [
                        'cycle_number' => (int) $cycle->cycle_number,
                        'progress_count' => (int) $cycle->progress_count,
                        'target_count' => $target,
                        'status' => $status,
                        'started_at' => $cycle->started_at,
                        'completed_at' => $cycle->completed_at,
                        'next_available_at' => $cycle->next_available_at,
                    ];
                })->values();

                /*
                 * Build all earned rewards for this task.
                 */
                $taskRewards = $rewardsByProgress->get(
                    $progress->id,
                    collect()
                )->map(function ($reward) use ($redemptionsByReward) {
                    $redemption = $redemptionsByReward->get(
                        $reward->id
                    );

                    return [
                        'reward_id' => $reward->public_id,
                        'cycle_number' => (int) $reward->cycle_number,
                        'status' => $reward->status,
                        'max_cost_kobo' => (int) $reward->max_cost_kobo,
                        'earned_at' => $reward->earned_at,
                        'delivered_at' => $reward->delivered_at,

                        'redemption' => $redemption ? [
                            'network' => $redemption->network,
                            'bundle' => json_decode(
                                $redemption->bundle_snapshot,
                                true,
                                512,
                                JSON_THROW_ON_ERROR
                            ),
                            'status' => $redemption->status,
                            'submitted_at' => $redemption->submitted_at,
                            'delivered_at' => $redemption->delivered_at,
                        ] : null,
                    ];
                })->values();

                $firstReward = $taskRewards->first();

                $tasks[] = [
                    'progress_id' => $progress->id,

                    'task' => $task,
                    'bundles' => $bundles,

                    /*
                     * Original fields retained for Flutter.
                     * These continue to represent the stored
                     * progress record until Flutter is updated.
                     */
                    'progress_count' => (int) $progress->progress_count,
                    'completed_at' => $progress->completed_at,

                    'reward_id' => $firstReward['reward_id'] ?? null,
                    'reward_status' => $firstReward['status'] ?? null,
                    'rewards' => $taskRewards,

                    /*
                     * New recurring-cycle fields.
                     */
                    'cycle_number' => $displayCycleNumber,
                    'cycle_status' => $cycleStatus,
                    'cycle_progress_count' => $displayProgressCount,
                    'cycle_completed_at' => $cycleCompletedAt,
                    'cycle_started_at' => $cycleStartedAt,
                    'next_available_at' => $nextAvailableAt,
                    'is_recurring' => $isRecurring,
                    'frequency_days' => $frequencyDays,

                    'next_cycle' => $nextCycleData,
                    'cycles' => $cycleHistory,
                ];
            }

            $items[] = [
                'participant_id' => $participant->id,
                'campaign_code' => $participant->code,
                'campaign_name' => $participant->name,
                'campaign_status' => $participant->status,
                'enrolled_at' => $participant->enrolled_at,
                'tasks' => $tasks,
            ];
        }

        return response()->json([
            'enrolments' => $items,
        ]);
    }

    private function savedAnnouncements(int $deviceId): array
    {
        $now = now();

        return DB::table('reward_campaign_popups as popups')
            ->join(
                'reward_campaigns as campaigns',
                'campaigns.id',
                '=',
                'popups.reward_campaign_id'
            )
            ->where('popups.enabled', true)
            ->where(
                'campaigns.environment',
                config('vtpass.environment')
            )
            ->where('campaigns.status', 'active')
            ->where('campaigns.show_in_app', true)
            ->whereNotExists(function ($query) use ($deviceId) {
                $query->selectRaw('1')
                    ->from('reward_participants as participants')
                    ->whereColumn(
                        'participants.reward_campaign_id',
                        'campaigns.id'
                    )
                    ->where('participants.reward_device_id', $deviceId);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('campaigns.starts_at')
                    ->orWhere('campaigns.starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('campaigns.enrollment_closes_at')
                    ->orWhere('campaigns.enrollment_closes_at', '>', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('campaigns.tasks_close_at')
                    ->orWhere('campaigns.tasks_close_at', '>', $now);
            })
            ->orderByDesc('popups.priority')
            ->orderByDesc('popups.id')
            ->get([
                'popups.announcement_key',
                'popups.heading',
                'popups.title',
                'popups.message',
                'popups.template',
                'popups.background_start',
                'popups.background_end',
                'popups.accent_color',
                'popups.text_color',
                'popups.button_text_color',
                'popups.revision',
                'campaigns.code as campaign_code',
                'campaigns.name as campaign_name',
            ])
            ->map(fn ($popup) => [
                'announcement_key' => $popup->announcement_key,
                'campaign_code' => $popup->campaign_code,
                'campaign_name' => $popup->campaign_name,
                'heading' => $popup->heading,
                'title' => $popup->title,
                'message' => $popup->message,
                'template' => $popup->template,
                'background_start' => $popup->background_start,
                'background_end' => $popup->background_end,
                'accent_color' => $popup->accent_color,
                'text_color' => $popup->text_color,
                'button_text_color' => $popup->button_text_color,
                'revision' => (int) $popup->revision,
            ])
            ->values()
            ->all();
    }
}
