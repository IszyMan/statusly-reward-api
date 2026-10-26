<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RewardReferralService;
use App\Services\RewardSaveService;
use Illuminate\Http\Request;

class RewardSaveController extends Controller
{
    public function store(
        Request $request,
        RewardSaveService $saves,
        RewardReferralService $referrals
    ) {
        $data = $request->validate([
            'content_hash' => [
                'required',
                'string',
                'regex:/^[a-fA-F0-9]{64}$/',
            ],

            'media_type' => [
                'required',
                'in:images,videos',
            ],

            'source' => [
                'required',
                'in:whatsapp,business',
            ],

            'size_bytes' => [
                'required',
                'integer',
                'min:1',
                'max:2147483647',
            ],
        ]);

        $device = $request->attributes->get('reward_device');

        $result = $saves->record($device->id, $data);

        // Also run on duplicate requests. If a previous request recorded
        // the save but failed before qualification, retrying can finish
        // qualification without crediting the save again.
        $referrals->qualify($device->id);

        return response()->json($result);
    }
}