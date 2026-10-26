<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RewardDeviceResetService
{
    public function reset(
        int $deviceId,
        int $adminId,
        array $data
    ): void {
        // A filtered admin page must not switch the actual provider mode.
        abort_unless(
            $data['environment'] === config('vtpass.environment'),
            403,
            'Reset must use the backend’s currently configured environment.'
        );

        if ($data['action'] === 'sandbox_restart') {
            abort_unless(
                app()->environment('local', 'testing')
                    && config('vtpass.environment') === 'sandbox',
                403,
                'Fresh test profiles are available only in local sandbox.'
            );
        }

        DB::transaction(function () use ($deviceId, $adminId, $data) {
            $device = DB::table('reward_devices')
                ->where('id', $deviceId)
                ->lockForUpdate()
                ->first();

            abort_unless($device, 404, 'Device not found.');

            if ($device->archived_at !== null) {
                $this->invalid('This device profile is already archived.');
            }

            if (! hash_equals(
                $device->referral_code,
                trim($data['confirmation'])
            )) {
                $this->invalid(
                    'Enter the exact referral code shown for this device.'
                );
            }

            $updates = [
                'api_token_hash' => null,
                'token_expires_at' => null,
                'updated_at' => now(),
            ];

            if ($data['action'] === 'sandbox_restart') {
                $hasLiveEnrolment = DB::table('reward_participants as participants')
                    ->join(
                        'reward_campaigns as campaigns',
                        'campaigns.id',
                        '=',
                        'participants.reward_campaign_id'
                    )
                    ->where('participants.reward_device_id', $deviceId)
                    ->where('campaigns.environment', 'live')
                    ->exists();

                $hasLiveReferral = DB::table('reward_referrals')
                    ->where('environment', 'live')
                    ->where(function ($query) use ($deviceId) {
                        $query->where('inviter_device_id', $deviceId)
                            ->orWhere('invited_device_id', $deviceId);
                    })
                    ->exists();

                if ($hasLiveEnrolment || $hasLiveReferral) {
                    $this->invalid(
                        'A profile with live participation cannot be '
                        .'restarted as a fresh test device.'
                    );
                }

                $hasPendingPayment = DB::table('reward_redemptions as redemptions')
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
                    ->where('participants.reward_device_id', $deviceId)
                    ->whereIn(
                        'redemptions.status',
                        ['submitting', 'pending']
                    )
                    ->exists();

                if ($hasPendingPayment) {
                    $this->invalid(
                        'Resolve this device’s pending payment before '
                        .'starting a fresh sandbox profile.'
                    );
                }

                // Keep the historical profile, but release its recognition
                // hash so the same test Android ID can register fresh.
                $updates['device_key_hash'] = bin2hex(random_bytes(32));
                $updates['archived_at'] = now();
                $updates['blocked_at'] = now();
                $updates['block_reason'] =
                    'Archived following an administrator sandbox restart.';
            }

            DB::table('reward_devices')
                ->where('id', $deviceId)
                ->update($updates);

            DB::table('reward_device_reset_logs')->insert([
                'reward_device_id' => $deviceId,
                'admin_user_id' => $adminId,
                'environment' => $data['environment'],
                'action' => $data['action'],
                'reason' => $data['reason'] ?? null,
                'created_at' => now(),
            ]);
        }, 3);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages([
            'reset' => $message,
        ]);
    }
}