<?php

namespace App\Console\Commands;

use App\Services\RewardReferralService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessReferralQualifications extends Command
{
    protected $signature = 'rewards:process-referrals';

    protected $description =
        'Process queued referral qualifications and funded referral milestones';

    public function handle(RewardReferralService $referrals): int
    {
        $lock = Cache::lock('rewards:process-referrals', 50);

        if (! $lock->get()) {
            $this->info('Referral processing is already running.');

            return self::SUCCESS;
        }

        try {
            $processed = $referrals->processPendingQualifications();

            $this->info(
                "Referral processing completed. Queue entries credited: {$processed}."
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);

            $this->error(
                'Referral processing failed. Check the Laravel log for details.'
            );

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
