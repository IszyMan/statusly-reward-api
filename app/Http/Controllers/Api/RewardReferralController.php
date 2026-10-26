<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RewardReferralService;
use Illuminate\Http\Request;

class RewardReferralController extends Controller
{
    public function claim(
        Request $request,
        RewardReferralService $service
    ) {
        $data = $request->validate([
            'referral_code' => [
                'required',
                'string',
                'max:16',
                'regex:/^[a-zA-Z0-9]+$/',
            ],
        ]);

        $device = $request->attributes->get('reward_device');

        return response()->json(
            $service->claim(
                $device->id,
                $data['referral_code']
            )
        );
    }
}