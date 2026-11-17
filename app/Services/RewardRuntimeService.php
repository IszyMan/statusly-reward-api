<?php

namespace App\Services;

class RewardRuntimeService
{
    public function available(): bool
    {
        if (! config('rewards.enabled', false)) {
            return false;
        }

        $environment = config('vtpass.environment');

        if ($environment === 'sandbox') {
            return true;
        }

        return $environment === 'live'
            && app()->environment('production')
            && config('rewards.allow_live', false);
    }

    public function assertAvailable(): void
    {
        abort_unless(
            $this->available(),
            503,
            'Rewards are temporarily unavailable. Please try again later.'
        );
    }
}