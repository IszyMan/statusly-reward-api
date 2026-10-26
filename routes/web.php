<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\CampaignController;
use App\Http\Middleware\EnsureRewardAdmin;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\RewardManagementController;
use App\Http\Controllers\Admin\DeviceAdminController;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AdminController::class, 'login'])
        ->name('login');

    Route::post('/login', [AdminController::class, 'authenticate'])
        ->middleware('throttle:5,1')
        ->name('authenticate');

    Route::middleware(EnsureRewardAdmin::class)->group(function () {
        Route::get('/', [AdminController::class, 'dashboard'])
            ->name('dashboard');

        Route::get('/campaigns', [CampaignController::class, 'index'])
            ->name('campaigns.index');

        Route::get('/campaigns/create', [CampaignController::class, 'create'])
            ->name('campaigns.create');

        Route::post('/campaigns', [CampaignController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('campaigns.store');

        Route::get(
            '/bundles/{network}',
            [CampaignController::class, 'bundles']
        )
            ->where('network', 'mtn|airtel|glo')
            ->middleware('throttle:30,1')
            ->name('bundles.index');

        Route::post(
            '/campaigns/{campaign}/activate',
            [CampaignController::class, 'activate']
        )
            ->whereNumber('campaign')
            ->name('campaigns.activate');
            
        Route::get(
            '/rewards',
            [RewardManagementController::class, 'rewards']
        )->name('rewards.index');

        Route::post(
            '/rewards/{rewardId}/check',
            [RewardManagementController::class, 'check']
        )
            ->whereUuid('rewardId')
            ->middleware('throttle:6,1')
            ->name('rewards.check');

        Route::get(
            '/devices',
            [DeviceAdminController::class, 'index']
        )->name('devices.index');

        Route::get(
            '/devices/{device}',
            [DeviceAdminController::class, 'show']
        )
            ->whereNumber('device')
            ->name('devices.show');

        Route::post(
            '/devices/{device}/reset',
            [DeviceAdminController::class, 'reset']
        )
            ->whereNumber('device')
            ->name('devices.reset');   

        Route::post('/logout', [AdminController::class, 'logout'])
            ->name('logout');
    });
});