<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RewardRedemptionService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class RewardManagementController extends Controller
{
    public function rewards(Request $request)
    {
        $filters = $request->validate([
            'environment' => ['nullable', 'in:sandbox,live'],
            'status' => [
                'nullable',
                'in:earned,processing,delivered,review',
            ],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $environment = $filters['environment']
            ?? config('vtpass.environment');

        $query = $this->rewardQuery()
            ->where('campaigns.environment', $environment);

        // Summary totals are for the selected environment.
        $counts = (clone $query)
            ->selectRaw('rewards.status, COUNT(*) AS total')
            ->groupBy('rewards.status')
            ->pluck('total', 'rewards.status');

        if (! empty($filters['status'])) {
            $query->where('rewards.status', $filters['status']);
        }

        $search = trim($filters['search'] ?? '');

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search) {
                $query->where('rewards.public_id', $search)
                    ->orWhere('devices.public_id', $search)
                    ->orWhere('devices.referral_code', $search)
                    ->orWhere('redemptions.request_id', $search)
                    ->orWhere('redemptions.phone', $search)
                    ->orWhere(
                        'campaigns.name',
                        'like',
                        '%'.$search.'%'
                    );
            });
        }

        $rewards = $query
            ->select([
                'rewards.public_id as reward_id',
                'rewards.status as reward_status',
                'rewards.earned_at',
                'rewards.delivered_at as reward_delivered_at',
                'progress.task_snapshot',
                'campaigns.name as campaign_name',
                'campaigns.environment',
                'devices.id as device_id',
                'devices.public_id as device_public_id',
                'devices.referral_code',
                'redemptions.id as redemption_id',
                'redemptions.request_id',
                'redemptions.network',
                'redemptions.phone',
                'redemptions.bundle_snapshot as redeemed_bundle',
                'redemptions.quoted_price_kobo',
                'redemptions.status as transaction_status',
                'redemptions.provider_code',
                'redemptions.provider_status',
                'redemptions.provider_transaction_id',
                'redemptions.message',
                'redemptions.submitted_at',
                'redemptions.checked_at',
                'redemptions.next_check_at',
                'redemptions.auto_check_attempts',
            ])
            ->orderByDesc('rewards.id')
            ->paginate(15)
            ->withQueryString();

        foreach ($rewards as $reward) {
            $reward->task = json_decode(
                $reward->task_snapshot,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            $reward->bundle = $reward->redeemed_bundle
                ? json_decode(
                    $reward->redeemed_bundle,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                )
                : null;
        }

        return view('admin.rewards.index', [
            'rewards' => $rewards,
            'counts' => $counts,
            'environment' => $environment,
            'status' => $filters['status'] ?? '',
            'search' => $search,
        ]);
    }

    public function devices(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $search = trim($data['search'] ?? '');

        $query = DB::table('reward_devices as devices')
            ->select([
                'devices.id',
                'devices.public_id',
                'devices.referral_code',
                'devices.first_seen_at',
                'devices.last_seen_at',
                'devices.blocked_at',
            ])
            ->selectSub(
                DB::table('reward_saved_media')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn(
                        'reward_device_id',
                        'devices.id'
                    ),
                'saved_count'
            )
            ->selectSub(
                DB::table('reward_participants')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn(
                        'reward_device_id',
                        'devices.id'
                    ),
                'participant_count'
            );

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
            ->paginate(20)
            ->withQueryString();

        return view('admin.devices.index', compact(
            'devices',
            'search'
        ));
    }

    public function device(Request $request, int $device)
    {
        $data = $request->validate([
            'environment' => ['nullable', 'in:sandbox,live'],
        ]);

        $environment = $data['environment']
            ?? config('vtpass.environment');

        // Never select token hashes or the device recognition hash.
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
            ])
            ->first();

        abort_unless($profile, 404, 'Device not found.');

        $savedCount = DB::table('reward_saved_media')
            ->where('reward_device_id', $device)
            ->count();

        $invitedCount = DB::table('reward_referrals')
            ->where('inviter_device_id', $device)
            ->where('environment', $environment)
            ->count();

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

        $progress = DB::table('reward_task_progress as progress')
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
                'reward_entitlements as rewards',
                'rewards.reward_task_progress_id',
                '=',
                'progress.id'
            )
            ->where('participants.reward_device_id', $device)
            ->where('campaigns.environment', $environment)
            ->select([
                'progress.id',
                'progress.task_snapshot',
                'progress.progress_count',
                'progress.completed_at',
                'campaigns.name as campaign_name',
                'campaigns.status as campaign_status',
                'participants.enrolled_at',
                'rewards.public_id as reward_id',
                'rewards.status as reward_status',
            ])
            ->orderByDesc('participants.id')
            ->orderBy('progress.id')
            ->paginate(20)
            ->withQueryString();

        foreach ($progress as $item) {
            $item->task = json_decode(
                $item->task_snapshot,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        }

        return view('admin.devices.show', compact(
            'profile',
            'savedCount',
            'invitedCount',
            'inviter',
            'progress',
            'environment'
        ));
    }

    public function check(
        string $rewardId,
        RewardRedemptionService $service
    ) {
        $reward = $this->rewardQuery()
            ->where('rewards.public_id', $rewardId)
            ->select([
                'devices.id as device_id',
                'campaigns.environment',
                'redemptions.id as redemption_id',
            ])
            ->first();

        abort_unless($reward, 404, 'Reward not found.');
        abort_unless(
            $reward->redemption_id,
            422,
            'This reward has not been redeemed.'
        );

        abort_unless(
            app()->environment('local', 'testing')
                && config('vtpass.environment') === 'sandbox'
                && $reward->environment === 'sandbox',
            403,
            'Manual checks currently support local sandbox only.'
        );

        try {
            // Checks the original reference. Never makes a purchase.
            $result = $service->check(
                (int) $reward->device_id,
                $rewardId
            );

            return back()->with(
                'success',
                'Transaction checked. Current status: '
                .$result['status'].'.'
            );
        } catch (Throwable $exception) {
            return back()->withErrors([
                'transaction' => 'The status check could not complete. '
                    .'The existing transaction has been preserved.',
            ]);
        }
    }

    private function rewardQuery(): Builder
    {
        return DB::table('reward_entitlements as rewards')
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
            ->join(
                'reward_campaigns as campaigns',
                'campaigns.id',
                '=',
                'participants.reward_campaign_id'
            )
            ->join(
                'reward_devices as devices',
                'devices.id',
                '=',
                'participants.reward_device_id'
            )
            ->leftJoin(
                'reward_redemptions as redemptions',
                'redemptions.reward_entitlement_id',
                '=',
                'rewards.id'
            );
    }
}