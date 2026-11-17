<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RewardCountryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RewardAvailabilityController extends Controller
{
    public function show(
        Request $request,
        RewardCountryService $countries
    ): JsonResponse {
        return response()
            ->json($countries->availability($request))
            ->header('Cache-Control', 'no-store, private');
    }
}