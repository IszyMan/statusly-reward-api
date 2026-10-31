<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RewardCampaignService;
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
                'campaigns.code',
                'campaigns.name',
                'campaigns.status',
            ])
            ->orderByDesc('participants.id')
            ->get();

        $items = [];

        foreach ($participants as $participant) {
            $progressRows = DB::table('reward_task_progress as progress')
                ->where(
                    'progress.reward_participant_id',
                    $participant->id
                )
                ->select([
                    'progress.id',
                    'progress.progress_count',
                    'progress.task_snapshot',
                    'progress.bundle_snapshot',
                    'progress.completed_at',
                ])
                ->orderBy('progress.id')
                ->get();

            $tasks = [];

            foreach ($progressRows as $progress) {
                $rewards = DB::table('reward_entitlements')
                    ->where('reward_task_progress_id', $progress->id)
                    ->orderBy('id')
                    ->get([
                        'id',
                        'public_id',
                        'status',
                        'max_cost_kobo',
                        'earned_at',
                        'delivered_at',
                    ])
                    ->map(function ($reward) {
                        $redemption = DB::table('reward_redemptions')
                            ->where('reward_entitlement_id', $reward->id)
                            ->first([
                                'network',
                                'bundle_snapshot',
                                'status',
                                'submitted_at',
                                'delivered_at',
                            ]);

                        return [
                            'reward_id' => $reward->public_id,
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
                    })
                    ->values();

                $firstReward = $rewards->first();

                $tasks[] = [
                    'progress_id' => $progress->id,
                    'task' => json_decode(
                        $progress->task_snapshot,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    ),
                    'bundles' => json_decode(
                        $progress->bundle_snapshot,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    ),
                    'progress_count' => (int) $progress->progress_count,
                    'completed_at' => $progress->completed_at,

                    // Keep these fields for compatibility with the current app.
                    'reward_id' => $firstReward['reward_id'] ?? null,
                    'reward_status' => $firstReward['status'] ?? null,

                    // New field: every reward earned from this task.
                    'rewards' => $rewards,
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

}