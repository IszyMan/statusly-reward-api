<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class RewardRedemptionService
{
    public function __construct(
        private VtpassService $vtpass
    ) {
    }

    public function redeem(
        int $deviceId,
        string $rewardId,
        string $network,
        string $phone
    ): array {
        $this->ensureSandbox();

        $reward = $this->ownedReward($deviceId, $rewardId);

        $existing = DB::table('reward_redemptions')
            ->where('reward_entitlement_id', $reward->id)
            ->first();

        if ($existing) {
            $this->checkDestination($existing, $network, $phone);

            if ($existing->status === 'delivered') {
                return [
                    'request_id' => $existing->request_id,
                    'environment' => $existing->environment,
                    'network' => $existing->network,
                    'phone' => $existing->phone,
                    'status' => 'already_claimed',
                    'provider_code' => $existing->provider_code,
                    'message' => 'You have already claimed this reward.',
                    'delivered_at' => $existing->delivered_at,
                ];
            }

            return $this->result($existing);
        }

        if ($reward->status !== 'earned') {
            $this->invalid('This reward is not available for redemption.');
        }

        $offers = json_decode(
            $reward->bundle_snapshot,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $offer = collect($offers)->firstWhere('network', $network);

        if (! $offer) {
            $this->invalid('This reward does not support that network.');
        }

        // Verify the selected variation against the current catalogue.
        $matches = collect($this->vtpass->variations($network))
            ->filter(
                fn ($plan) => is_array($plan)
                    && ($plan['variation_code'] ?? null)
                        === $offer['variation_code']
            )
            ->values();

        if ($matches->count() !== 1) {
            $this->invalid('The selected bundle needs administrator review.');
        }

        $plan = $matches->first();

        $price = $this->money(
            $plan['variation_amount'] ?? null
        );

        $providerName = trim(
            (string) ($plan['name'] ?? '')
        );

        if (
            $price === null
            || $price < 1
            || $price > (int) $reward->max_cost_kobo
            || $providerName !== trim($offer['bundle_name'])
            || $price !== (int) $offer['listed_price_kobo']
            || strtolower((string) ($plan['fixedPrice'] ?? '')) !== 'yes'
        ) {
            $this->invalid(
                'This bundle has changed or is not a fixed-price offer. '
                .'No purchase was made. Administrator review is needed.'
            );
        }

        $prepared = DB::transaction(function () use (
            $deviceId,
            $rewardId,
            $network,
            $phone,
            $offer,
            $price
        ) {
            // Serialize redemptions belonging to the same device.
            $device = DB::table('reward_devices')
                ->where('id', $deviceId)
                ->lockForUpdate()
                ->first();

            abort_unless($device, 401, 'Device unavailable.');
            abort_if($device->blocked_at !== null, 403, 'Rewards unavailable.');

            $reward = $this->ownedReward($deviceId, $rewardId);

            $lockedReward = DB::table('reward_entitlements')
                ->where('id', $reward->id)
                ->lockForUpdate()
                ->first();

            $existing = DB::table('reward_redemptions')
                ->where('reward_entitlement_id', $reward->id)
                ->first();

            if ($existing) {
                $this->checkDestination($existing, $network, $phone);

                return [
                    'id' => $existing->id,
                    'send' => false,
                ];
            }

            
            if ($lockedReward->status !== 'earned') {
                $this->invalid('This reward is not available for redemption.');
            }

            // Prevent another device from claiming this phone number.
            $this->assertPhoneAvailable($deviceId, $phone);

            $now = now();

            
            // Reserve the phone number. The unique phone_hash index
            // prevents two different devices from reserving the same number.
            try {
                DB::table('reward_device_phone_numbers')->insert([
                    'reward_device_id' => $deviceId,
                    'phone_hash' => $this->phoneHash($phone),
                    'network' => $network,
                    'phone_last4' => substr($phone, -4),
                    'bound_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (\Illuminate\Database\QueryException $exception) {
                // Re-read the owner after a duplicate-key or concurrent insert.
                $owner = DB::table('reward_device_phone_numbers')
                    ->where('phone_hash', $this->phoneHash($phone))
                    ->first();

                // Re-throw unrelated database errors.
                if (! $owner) {
                    throw $exception;
                }

                // Never allow another device to use this number.
                if ((int) $owner->reward_device_id !== $deviceId) {
                    abort(
                        409,
                        'This phone number is already assigned to another device.'
                    );
                }

                // This device already owns the number, so it can reuse it.
                if ($owner->network !== $network) {
                    abort(
                        409,
                        'This phone number is already registered to a different network.'
                    );
                }
            }



            $requestId = CarbonImmutable::now('Africa/Lagos')
                ->format('YmdHi')
                .bin2hex(random_bytes(10));

            $id = DB::table('reward_redemptions')->insertGetId([
                'reward_entitlement_id' => $reward->id,
                'environment' => config('vtpass.environment'),
                'request_id' => $requestId,
                'network' => $network,
                'phone' => $phone,
                'bundle_snapshot' => json_encode(
                    $offer,
                    JSON_THROW_ON_ERROR
                ),
                'quoted_price_kobo' => $price,
                'status' => 'submitting',
                'message' => 'Submitting the reward.',
                'submitted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('reward_entitlements')
                ->where('id', $reward->id)
                ->update([
                    'status' => 'processing',
                    'updated_at' => $now,
                ]);

            return [
                'id' => $id,
                'send' => true,
            ];
        }, 3);

        $redemption = DB::table('reward_redemptions')
            ->find($prepared['id']);

        if ($prepared['send']) {
            try {
                // Only the request that created the redemption sends /pay.
                // All later requests return this same record.
                $response = $this->vtpass->purchaseData([
                    'request_id' => $redemption->request_id,
                    'serviceID' => $offer['service_id'],
                    'billersCode' => $redemption->phone,
                    'variation_code' => $offer['variation_code'],
                    'phone' => $redemption->phone,
                ]);

                $this->applyResponse($redemption->id, $response);
            } catch (Throwable $exception) {
                $this->markUncertain($redemption->id);
            }
        }

        return $this->result(
            DB::table('reward_redemptions')->find($redemption->id)
        );
    }

    public function check(int $deviceId, string $rewardId): array
    {
        $this->ensureSandbox();

        $reward = $this->ownedReward($deviceId, $rewardId);

        $redemption = DB::table('reward_redemptions')
            ->where('reward_entitlement_id', $reward->id)
            ->first();

        abort_unless($redemption, 404, 'No redemption exists for this reward.');

        if ($redemption->status !== 'delivered') {
            try {
                $response = $this->vtpass->requery(
                    $redemption->request_id
                );

                $this->applyResponse($redemption->id, $response);
            } catch (Throwable $exception) {
                $this->markUncertain($redemption->id);
            }
        }

        return $this->result(
            DB::table('reward_redemptions')->find($redemption->id)
        );
    }

    private function applyResponse(int $id, array $response): void
    {
        DB::transaction(function () use ($id, $response) {
            $redemption = DB::table('reward_redemptions')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            // A later pending response must not undo confirmed delivery.
            if ($redemption->status === 'delivered') {
                return;
            }

            $transaction = data_get(
                $response,
                'content.transactions',
                []
            );

            if (! is_array($transaction)) {
                $transaction = [];
            }

            $code = (string) ($response['code'] ?? '');
            $providerStatus = strtolower(
                (string) ($transaction['status'] ?? '')
            );

            $requestMatches = (
                (string) ($response['requestId'] ?? '')
                === $redemption->request_id
            );

            $recipientMatches = (
                (string) ($transaction['unique_element'] ?? '')
                === $redemption->phone
            );

            $providerAmount = $this->money(
                $transaction['unit_price'] ?? null
            );

            $transactionId = (string) (
                $transaction['transactionId'] ?? ''
            );

            $delivered = in_array($code, ['000', '001'], true)
                && $providerStatus === 'delivered'
                && $requestMatches
                && $recipientMatches
                && $providerAmount === (int) $redemption->quoted_price_kobo
                && $transactionId !== ''
                && strlen($transactionId) <= 120;

            $failedCodes = [
                '010', '011', '012', '013', '016', '017', '018',
                '020', '021', '022', '023', '024', '025', '026',
                '027', '028', '031', '032', '034', '035', '040',
                '085', '087', '091',
            ];

            $needsReview = $code === '019'
                || in_array($code, $failedCodes, true)
                || $providerStatus === 'failed'
                || $providerStatus === 'delivered';

            $status = $delivered
                ? 'delivered'
                : ($needsReview ? 'review' : 'pending');

            $message = match ($status) {
                'delivered' => 'Data reward delivered.',
                'review' => 'This transaction needs administrator review.',
                default => 'Awaiting confirmation. Check the existing transaction.',
            };

            $now = now();

            DB::table('reward_redemptions')
                ->where('id', $id)
                ->update([
                    'status' => $status,
                    'provider_code' => substr($code, 0, 20),
                    'provider_status' => substr($providerStatus, 0, 40),
                    'provider_transaction_id' => $delivered
                        ? $transactionId
                        : null,
                    'message' => $message,
                    'checked_at' => $now,
                    'delivered_at' => $delivered ? $now : null,
                    'updated_at' => $now,
                ]);

            DB::table('reward_entitlements')
                ->where('id', $redemption->reward_entitlement_id)
                ->update([
                    'status' => match ($status) {
                        'delivered' => 'delivered',
                        'review' => 'review',
                        default => 'processing',
                    },
                    'delivered_at' => $delivered ? $now : null,
                    'updated_at' => $now,
                ]);

                if ($delivered) {
                    $ownerDeviceId = DB::table('reward_entitlements as e')
                        ->join(
                            'reward_task_progress as p',
                            'p.id',
                            '=',
                            'e.reward_task_progress_id'
                        )
                        ->join(
                            'reward_participants as rp',
                            'rp.id',
                            '=',
                            'p.reward_participant_id'
                        )
                        ->where('e.id', $redemption->reward_entitlement_id)
                        ->value('rp.reward_device_id');

                    $phoneHash = $this->phoneHash($redemption->phone);

                    $phoneReservation = DB::table('reward_device_phone_numbers')
                        ->where('phone_hash', $phoneHash)
                        ->lockForUpdate()
                        ->first();

                    if (
                        ! $phoneReservation ||
                        $ownerDeviceId === null ||
                        (int) $phoneReservation->reward_device_id !== (int) $ownerDeviceId ||
                        $phoneReservation->network !== $redemption->network
                    ) {
                        throw new \RuntimeException(
                            'Phone reservation does not match the redemption device and network.'
                        );
                    }

                    DB::table('reward_device_phone_numbers')
                        ->where('phone_hash', $phoneHash)
                        ->where('reward_device_id', $ownerDeviceId)
                        ->where('network', $redemption->network)
                        ->whereNull('bound_at')
                        ->update([
                            'bound_at' => $now,
                            'updated_at' => $now,
                        ]);
                }

        }, 3);
        
    }

    private function ownedReward(int $deviceId, string $rewardId): object
    {
        $reward = DB::table('reward_entitlements as rewards')
            ->join(
                'reward_task_progress as progress',
                'progress.id',
                '=',
                'rewards.reward_task_progress_id'
            )
            ->join(
                'reward_participants as participants',
                'participants.id',
                '=',
                'progress.reward_participant_id'
            )
            ->join(
                'reward_campaigns as campaigns',
                'campaigns.id',
                '=',
                'participants.reward_campaign_id'
            )
            ->where('rewards.public_id', $rewardId)
            ->where('participants.reward_device_id', $deviceId)
            ->where(
                'campaigns.environment',
                config('vtpass.environment')
            )
            ->select('rewards.*')
            ->first();

        abort_unless($reward, 404, 'Reward not found.');

        return $reward;
    }


    private function phoneHash(string $phone): string
    {
        // The controller normalizes Nigerian numbers to 080... format.
        return hash('sha256', $phone);
    }

    private function assertPhoneAvailable(
        int $deviceId,
        string $phone
    ): void {
        $hash = $this->phoneHash($phone);

        $owner = DB::table('reward_device_phone_numbers')
            ->where('phone_hash', $hash)
            ->first();

        if ($owner && (int) $owner->reward_device_id !== $deviceId) {
            abort(
                409,
                'This phone number is already assigned to another rewards device.'
            );
        }
    }


    private function checkDestination(
        object $redemption,
        string $network,
        string $phone
    ): void {
        abort_if(
            $redemption->network !== $network
                || $redemption->phone !== $phone,
            409,
            'This reward already has a redemption with a locked destination.'
        );
    }

    private function markUncertain(int $id): void
    {
        DB::table('reward_redemptions')
            ->where('id', $id)
            ->whereIn('status', ['submitting', 'pending'])
            ->update([
                'status' => 'pending',
                'message' => 'Confirmation unavailable. Check this transaction; '
                    .'do not create another purchase.',
                'updated_at' => now(),
            ]);
    }

    private function result(object $redemption): array
    {
        return [
            'request_id' => $redemption->request_id,
            'environment' => $redemption->environment,
            'network' => $redemption->network,
            'phone' => $redemption->phone,
            'status' => $redemption->status,
            'provider_code' => $redemption->provider_code,
            'message' => $redemption->message,
            'delivered_at' => $redemption->delivered_at,
        ];
    }

    private function money(mixed $value): ?int
    {
        if (! is_string($value)
            && ! is_int($value)
            && ! is_float($value)) {
            return null;
        }

        $value = (string) $value;

        if (! preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $value)) {
            return null;
        }

        $parts = explode('.', $value, 2);

        return ((int) $parts[0] * 100)
            + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    private function ensureSandbox(): void
    {
        abort_unless(
            app()->environment('local', 'testing')
                && config('vtpass.environment') === 'sandbox',
            403,
            'Redemption currently supports local sandbox testing only.'
        );
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages([
            'reward' => $message,
        ]);
    }
}