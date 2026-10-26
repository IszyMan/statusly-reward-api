<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RewardRedemptionService;
use Illuminate\Http\Request;

class RewardRedemptionController extends Controller
{
    public function store(
        Request $request,
        string $rewardId,
        RewardRedemptionService $service
    ) {
        $data = $request->validate([
            'network' => ['required', 'in:mtn,airtel,glo'],

            'phone' => [
                'required',
                'string',
                'regex:/^(?:0[789]\d{9}|\+?234[789]\d{9})$/',
            ],
        ]);

        $phone = $data['phone'];

        if (str_starts_with($phone, '+234')) {
            $phone = '0'.substr($phone, 4);
        } elseif (str_starts_with($phone, '234')) {
            $phone = '0'.substr($phone, 3);
        }

        $device = $request->attributes->get('reward_device');

        return response()->json(
            $service->redeem(
                $device->id,
                $rewardId,
                $data['network'],
                $phone
            )
        );
    }

    public function check(
        Request $request,
        string $rewardId,
        RewardRedemptionService $service
    ) {
        $device = $request->attributes->get('reward_device');

        return response()->json(
            $service->check($device->id, $rewardId)
        );
    }
}