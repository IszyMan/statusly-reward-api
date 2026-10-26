<?php

namespace App\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateRewardDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! is_string($token)
            || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return response()->json([
                'message' => 'A valid device token is required.',
            ], 401);
        }

        $device = DB::table('reward_devices')
            ->where('api_token_hash', hash('sha256', $token))
            ->first();

        if (! $device
            || ! $device->token_expires_at
            || CarbonImmutable::parse(
                $device->token_expires_at
            )->isPast()) {
            return response()->json([
                'message' => 'Device session expired or unavailable.',
            ], 401);
        }

        if ($device->blocked_at !== null) {
            return response()->json([
                'message' => 'Rewards access is unavailable for this device.',
            ], 403);
        }

        $request->attributes->set('reward_device', $device);

        return $next($request);
    }
}