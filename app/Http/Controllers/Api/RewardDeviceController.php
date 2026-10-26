<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class RewardDeviceController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'android_id' => [
                'required',
                'string',
                'regex:/^[a-fA-F0-9]{16}$/',
                'not_in:0000000000000000',
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

        $token = bin2hex(random_bytes(32));

        $expiresAt = now()->addHours(
            (int) config('rewards.device_token_hours', 24)
        );

        $result = DB::transaction(function () use (
            $deviceHash,
            $token,
            $expiresAt
        ) {
            $now = now();

            $inserted = DB::table('reward_devices')->insertOrIgnore([
                'public_id' => (string) Str::uuid(),
                'device_key_hash' => $deviceHash,
                'referral_code' => 'ST'.strtoupper(
                    bin2hex(random_bytes(6))
                ),
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
                abort(403, 'Rewards access is unavailable for this device.');
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
            ];
        }, 3);

        return response()->json([
            'environment' => 'sandbox',
            'device' => $result['device'],
            'new_device' => $result['new_device'],
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
            'environment' => 'sandbox',
            'device' => [
                'id' => $device->public_id,
                'referral_code' => $device->referral_code,
            ],
        ]);
    }
}