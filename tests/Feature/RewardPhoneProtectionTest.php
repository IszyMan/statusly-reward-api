<?php

namespace Tests\Feature;

use App\Services\RewardRedemptionService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RewardPhoneProtectionTest extends TestCase
{
    public function test_phone_assigned_to_another_device_is_rejected(): void
    {
        $devices = DB::table('reward_devices')
            ->orderBy('id')
            ->limit(2)
            ->pluck('id');

        $this->assertCount(
            2,
            $devices,
            'Two existing reward devices are required for this test.'
        );

        $deviceA = (int) $devices[0];
        $deviceB = (int) $devices[1];

        $phone = 'STATUSLY-TEST-PHONE-001';
        $phoneHash = hash('sha256', $phone);

        DB::table('reward_device_phone_numbers')->insert([
            'reward_device_id' => $deviceA,
            'phone_hash' => $phoneHash,
            'network' => 'mtn',
            'phone_last4' => '0001',
            'bound_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(RewardRedemptionService::class);

        $method = new \ReflectionMethod(
            RewardRedemptionService::class,
            'assertPhoneAvailable'
        );

        $method->setAccessible(true);

        try {
            $method->invoke($service, $deviceB, $phone);

            $this->fail(
                'A second device was allowed to use the same phone number.'
            );
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->assertSame(
                409,
                $exception->getStatusCode(),
                'The service should reject the phone number with HTTP 409.'
            );
        } finally {
            DB::table('reward_device_phone_numbers')
                ->where('phone_hash', $phoneHash)
                ->delete();
        }
    }
}
