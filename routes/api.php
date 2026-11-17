<?php

use App\Http\Controllers\Api\RewardCampaignController;
use App\Http\Controllers\Api\RewardDeviceController;
use App\Http\Middleware\AuthenticateRewardDevice;
use App\Http\Middleware\EnsureLocalRewardSandbox;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\RewardSaveController;
use App\Http\Controllers\Api\RewardReferralController;
use App\Http\Controllers\Api\RewardRedemptionController;
use App\Http\Controllers\Api\RewardAvailabilityController;
use App\Http\Middleware\EnsureNigerianRewardCountry;

Route::prefix('rewards')
    ->middleware(EnsureLocalRewardSandbox::class)
    ->group(function () {

        Route::get(
            '/availability',
            [RewardAvailabilityController::class, 'show']
        )->middleware('throttle:60,1');

        Route::post(
            '/devices/register',
            [RewardDeviceController::class, 'register']
        )->middleware('throttle:reward-registration');

        Route::middleware([
            AuthenticateRewardDevice::class,
            'throttle:reward-api',
        ])->group(function () {
            Route::get(
                '/device',
                [RewardDeviceController::class, 'show']
            );

            Route::get(
                '/campaigns',
                [RewardCampaignController::class, 'index']
            );

            Route::post(
                '/campaigns/{campaignCode}/join',
                [RewardCampaignController::class, 'join']
            )->middleware(EnsureNigerianRewardCountry::class);

            Route::get(
                '/enrolments',
                [RewardCampaignController::class, 'enrolments']
            );

            Route::post(
                '/saves',
                [RewardSaveController::class, 'store']
            )->middleware(EnsureNigerianRewardCountry::class);

            Route::post(
                '/referrals/claim',
                [RewardReferralController::class, 'claim']
            )->middleware(EnsureNigerianRewardCountry::class);

            Route::post(
                '/rewards/{rewardId}/redeem',
                [RewardRedemptionController::class, 'store']
            )->whereUuid('rewardId');

            Route::post(
                '/rewards/{rewardId}/check',
                [RewardRedemptionController::class, 'check']
            )->whereUuid('rewardId');
        });
    });