<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RewardReferralService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RewardDeviceController extends Controller
{
    public function register(
        Request $request,
        RewardReferralService $referrals
    ) {
        $data = $request->validate([
            'android_id' => [
                'required',
                'string',
                'regex:/^[a-fA-F0-9]{16}$/',
                'not_in:0000000000000000',
            ],
            'install_referral_code' => [
                'nullable',
                'string',
                'regex:/^[a-zA-Z0-9]{6}$/',
            ],
        ]);

        $hashKey = (string) config('rewards.device_hash_key');

        if (strlen($hashKey) < 32) {
            throw new RuntimeException(
                'Configure REWARDS_DEVICE_HASH_KEY first.'
            );
        }

        $androidId = strtolower($data['android_id']);

        $deviceHash = hash_hmac(
            'sha256',
            'android:'.$androidId,
            $hashKey
        );

        $installReferralCode = strtoupper(
            trim((string) ($data['install_referral_code'] ?? ''))
        );

        $token = bin2hex(random_bytes(32));

        $expiresAt = now()->addHours(
            (int) config('rewards.device_token_hours', 24)
        );

        $result = DB::transaction(function () use (
            $deviceHash,
            $token,
            $expiresAt,
            $installReferralCode,
            $referrals
        ) {
            $now = now();

            $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

            do {
                $referralCode = '';

                for ($i = 0; $i < 6; $i++) {
                    $referralCode .= $characters[
                        random_int(0, strlen($characters) - 1)
                    ];
                }
            } while (
                DB::table('reward_devices')
                    ->where('referral_code', $referralCode)
                    ->exists()
            );

            $inserted = DB::table('reward_devices')->insertOrIgnore([
                'public_id' => (string) Str::uuid(),
                'device_key_hash' => $deviceHash,
                'referral_code' => $referralCode,
                'api_token_hash' => null,
                'token_expires_at' => null,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $device = DB::table('reward_devices')
                ->where('device_key_hash', $deviceHash)
                ->lockForUpdate()
                ->first();

            if (! $device) {
                throw new RuntimeException(
                    'Could not create or retrieve the device profile.'
                );
            }

            if ($device->blocked_at !== null) {
                abort(
                    403,
                    'Rewards access is unavailable for this device.'
                );
            }

            $attribution = [
                'status' => 'none',
                'claimed' => false,
            ];

            if ($installReferralCode !== '') {
                try {
                    // Record attribution only.
                    // Qualification remains in the existing save flow.
                    $claim = $referrals->claim(
                        (int) $device->id,
                        $installReferralCode
                    );

                    $attribution = [
                        'status' => 'attributed',
                        'claimed' => (bool) ($claim['claimed'] ?? false),
                        'already_claimed' => (bool) (
                            $claim['already_claimed'] ?? false
                        ),
                    ];
                } catch (ValidationException $exception) {
                    // An invalid or ineligible referral must not prevent
                    // normal device registration.
                    //
                    // The claim service retains its existing rules:
                    // no self-referral, no replacement attribution,
                    // and no claim after saves or campaign enrolment.
                    $attribution = [
                        'status' => 'rejected',
                        'claimed' => false,
                        'message' => collect($exception->errors())
                            ->flatten()
                            ->first() ?? 'Referral attribution was rejected.',
                    ];
                }
            }

            DB::table('reward_devices')
                ->where('id', $device->id)
                ->update([
                    'api_token_hash' => hash('sha256', $token),
                    'token_expires_at' => $expiresAt,
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                ]);

            return [
                'device' => [
                    'id' => $device->public_id,
                    'referral_code' => $device->referral_code,
                ],
                'new_device' => $inserted === 1,
                'referral_attribution' => $attribution,
            ];
        }, 3);

        return response()->json([
            'environment' => config('vtpass.environment'),
            'device' => $result['device'],
            'new_device' => $result['new_device'],
            'referral_attribution' => $result['referral_attribution'],
            'token' => $token,
            'token_expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function show(Request $request)
    {
        $device = $request->attributes->get('reward_device');

        DB::table('reward_devices')
            ->where('id', $device->id)
            ->update([
                'last_seen_at' => now(),
            ]);

        return response()->json([
            'environment' => config('vtpass.environment'),
            'device' => [
                'id' => $device->public_id,
                'referral_code' => $device->referral_code,
            ],
        ]);
    }
}