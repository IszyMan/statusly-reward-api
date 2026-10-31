<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RewardDeviceResetService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DeviceAdminController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'environment' => ['nullable', 'in:sandbox,live'],
        ]);

        $search = trim($data['search'] ?? '');
        $environment = $data['environment']
            ?? config('vtpass.environment');

        $query = DB::table('reward_devices as devices')
            ->whereNull('devices.archived_at')
            ->whereExists(function (Builder $query) use ($environment) {
                $query->selectRaw('1')
                    ->from('reward_participants as participants')
                    ->join(
                        'reward_campaigns as campaigns',
                        'campaigns.id',
                        '=',
                        'participants.reward_campaign_id'
                    )
                    ->join(
                        'reward_task_progress as progress',
                        'progress.reward_participant_id',
                        '=',
                        'participants.id'
                    )
                    ->whereColumn(
                        'participants.reward_device_id',
                        'devices.id'
                    )
                    ->where('campaigns.environment', $environment);
            })
            ->select([
                'devices.id',
                'devices.public_id',
                'devices.referral_code',
                'devices.last_seen_at',
                'devices.blocked_at',
            ]);

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search) {
                $query->where('devices.public_id', $search)
                    ->orWhere('devices.referral_code', $search);

                if (ctype_digit($search)) {
                    $query->orWhere('devices.id', (int) $search);
                }
            });
        }

        $devices = $query
            ->orderByDesc('devices.id')
            ->paginate(15)
            ->withQueryString();

        // Fetch the page's task progress together, rather than one
        // database request for each device.
        $tasks = $this->progressQuery()
            ->where('campaigns.environment', $environment)
            ->whereIn(
                'participants.reward_device_id',
                $devices->getCollection()->pluck('id')->all()
            )
            ->orderByDesc('participants.id')
            ->orderBy('progress.id')
            ->get();

        $this->decodeTasks($tasks);

        $tasksByDevice = $tasks->groupBy('device_id');

        foreach ($devices as $device) {
            $device->campaigns = $tasksByDevice
                ->get($device->id, collect())
                ->groupBy('campaign_id');
        }

        return view('admin.devices.index', compact(
            'devices',
            'search',
            'environment'
        ));
    }

    public function show(Request $request, int $device)
    {
        $data = $request->validate([
            'environment' => ['nullable', 'in:sandbox,live'],
        ]);

        $environment = $data['environment']
            ?? config('vtpass.environment');

        $profile = DB::table('reward_devices')
            ->where('id', $device)
            ->select([
                'id',
                'public_id',
                'referral_code',
                'first_seen_at',
                'last_seen_at',
                'blocked_at',
                'block_reason',
                'archived_at',
            ])
            ->first();

        abort_unless($profile, 404, 'Device not found.');

        $progress = $this->progressQuery()
            ->where('participants.reward_device_id', $device)
            ->where('campaigns.environment', $environment)
            ->orderByDesc('participants.id')
            ->orderBy('progress.id')
            ->paginate(20, ['*'], 'tasks_page')
            ->withQueryString();

        $this->decodeTasks($progress->getCollection());

        $friends = DB::table('reward_referrals as referrals')
            ->join(
                'reward_devices as invited',
                'invited.id',
                '=',
                'referrals.invited_device_id'
            )
            ->where('referrals.inviter_device_id', $device)
            ->where('referrals.environment', $environment)
            ->select([
                'referrals.id as referral_id',
                'referrals.attributed_at',
                'invited.id',
                'invited.public_id',
                'invited.referral_code',
                'invited.archived_at',
            ])
            ->selectSub(
                DB::table('reward_saved_media')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn(
                        'reward_device_id',
                        'invited.id'
                    ),
                'saved_count'
            )
            ->selectSub(
                DB::table('reward_participants as own_participation')
                    ->join(
                        'reward_campaigns as own_campaign',
                        'own_campaign.id',
                        '=',
                        'own_participation.reward_campaign_id'
                    )
                    ->selectRaw('COUNT(*)')
                    ->whereColumn(
                        'own_participation.reward_device_id',
                        'invited.id'
                    )
                    ->where('own_campaign.environment', $environment),
                'enrolment_count'
            )
            ->orderByDesc('referrals.id')
            ->paginate(10, ['*'], 'friends_page')
            ->withQueryString();

        $friendIds = $friends->getCollection()->pluck('id')->all();
        $referralIds = $friends->getCollection()
            ->pluck('referral_id')
            ->all();

        // Three recent file records per invited device.
        $rankedFiles = DB::table('reward_saved_media')
            ->whereIn('reward_device_id', $friendIds)
            ->select([
                'id',
                'reward_device_id',
                'content_hash',
                'media_type',
                'source',
                'accepted_at',
            ])
            ->selectRaw(
                'ROW_NUMBER() OVER ('
                .'PARTITION BY reward_device_id ORDER BY id DESC'
                .') AS file_rank'
            );

        $friendFiles = DB::query()
            ->fromSub($rankedFiles, 'ranked_files')
            ->where('file_rank', '<=', 3)
            ->orderByDesc('id')
            ->get()
            ->groupBy('reward_device_id');

        // A friend is marked credited only when a credit actually exists.
        $credits = DB::table('reward_referral_credits as credits')
            ->join(
                'reward_task_progress as progress',
                'progress.id',
                '=',
                'credits.reward_task_progress_id'
            )
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
            ->whereIn('credits.reward_referral_id', $referralIds)
            ->where('participants.reward_device_id', $device)
            ->where('campaigns.environment', $environment)
            ->leftJoin(
                'reward_campaign_tasks as current_tasks',
                'current_tasks.id',
                '=',
                'progress.reward_campaign_task_id'
            )
            ->select([
                'credits.reward_referral_id',
                'credits.qualified_at',
                'campaigns.name as campaign_name',
                'progress.task_snapshot',
                'current_tasks.title as current_task_title',
            ])
            ->get();

        foreach ($credits as $credit) {
            $credit->task = json_decode(
                $credit->task_snapshot,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if ($credit->current_task_title !== null) {
                $credit->task['title'] = $credit->current_task_title;
            }
        }

        $creditsByReferral = $credits->groupBy('reward_referral_id');

        foreach ($friends as $friend) {
            $friend->files = $friendFiles->get(
                $friend->id,
                collect()
            );

            $friend->credits = $creditsByReferral->get(
                $friend->referral_id,
                collect()
            );
        }

        $files = DB::table('reward_saved_media')
            ->where('reward_device_id', $device)
            ->select([
                'id',
                'content_hash',
                'media_type',
                'source',
                'size_bytes',
                'accepted_at',
            ])
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'files_page')
            ->withQueryString();

        $inviter = DB::table('reward_referrals as referrals')
            ->join(
                'reward_devices as inviter',
                'inviter.id',
                '=',
                'referrals.inviter_device_id'
            )
            ->where('referrals.invited_device_id', $device)
            ->where('referrals.environment', $environment)
            ->select([
                'inviter.id',
                'inviter.referral_code',
            ])
            ->first();

        $resetLogs = DB::table('reward_device_reset_logs as logs')
            ->leftJoin('users', 'users.id', '=', 'logs.admin_user_id')
            ->where('logs.reward_device_id', $device)
            ->select([
                'logs.action',
                'logs.environment',
                'logs.reason',
                'logs.created_at',
                'users.name as admin_name',
            ])
            ->orderByDesc('logs.id')
            ->limit(10)
            ->get();

        return view('admin.devices.show', compact(
            'profile',
            'environment',
            'progress',
            'friends',
            'files',
            'inviter',
            'resetLogs'
        ));
    }

    public function reset(
        Request $request,
        int $device,
        RewardDeviceResetService $service
    ) {
        $data = $request->validate([
            'action' => ['required', 'in:session,sandbox_restart'],
            'environment' => ['required', 'in:sandbox,live'],
            'confirmation' => ['required', 'string', 'max:16'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $service->reset(
            $device,
            (int) $request->user()->id,
            $data
        );

        if ($data['action'] === 'sandbox_restart') {
            return redirect()
                ->route('admin.devices.index', [
                    'environment' => 'sandbox',
                ])
                ->with(
                    'success',
                    'Test profile archived. Registering the same phone '
                    .'again will create a fresh sandbox profile. '
                    .'Previous campaign reservations and history were retained.'
                );
        }

        return redirect()
            ->route('admin.devices.show', [
                'device' => $device,
                'environment' => $data['environment'],
            ])
            ->with(
                'success',
                'Device session reset. The device must reconnect. '
                .'Campaign progress and reward history were preserved.'
            );
    }

    
    private function progressQuery(): Builder
    {
        return DB::table('reward_task_progress as progress')
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
            ->leftJoin(
                'reward_campaign_tasks as current_tasks',
                'current_tasks.id',
                '=',
                'progress.reward_campaign_task_id'
            )
            ->leftJoin(
                'reward_entitlements as rewards',
                'rewards.reward_task_progress_id',
                '=',
                'progress.id'
            )
            ->select([
                'progress.id',
                'progress.task_snapshot',
                'progress.progress_count',
                'progress.completed_at',
                'participants.reward_device_id as device_id',
                'participants.enrolled_at',
                'campaigns.id as campaign_id',
                'campaigns.name as campaign_name',
                'campaigns.status as campaign_status',
                'current_tasks.title as current_task_title',
                'rewards.public_id as reward_id',
                'rewards.status as reward_status',
            ]);
    }


    
    private function decodeTasks(Collection $rows): void
    {
        foreach ($rows as $item) {
            $item->task = json_decode(
                $item->task_snapshot,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            // Display the current title when the original task still exists.
            if ($item->current_task_title !== null) {
                $item->task['title'] = $item->current_task_title;
            }

            $item->target = max(
                1,
                (int) ($item->task['target_count'] ?? 1)
            );

            $item->percentage = min(
                100,
                max(
                    0,
                    ((int) $item->progress_count / $item->target) * 100
                )
            );
        }
    }

}