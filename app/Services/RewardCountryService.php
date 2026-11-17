<?php

namespace App\Services;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Illuminate\Http\Request;
use Throwable;

class RewardCountryService
{
    public function countryCode(Request $request): ?string
    {
        if (app()->environment('local', 'testing')) {
            $override = strtoupper(trim(
                (string) config('reward_country.local_override')
            ));

            if (preg_match('/^[A-Z]{2}$/', $override)) {
                return $override;
            }
        }

        // Use Laravel's resolved client IP.
        // Do not read arbitrary forwarded-IP headers manually.
        $ip = $request->ip();

        if (
            ! is_string($ip)
            || filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false
        ) {
            return null;
        }

        $reader = null;

        try {
            $reader = new Reader(
                (string) config('reward_country.database_path')
            );

            $country = $reader->country($ip)->country->isoCode;

            return $country !== null
                ? strtoupper($country)
                : null;
        } catch (AddressNotFoundException) {
            return null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        } finally {
            if ($reader !== null) {
                $reader->close();
            }
        }
    }

    public function availability(Request $request): array
    {
        $country = $this->countryCode($request);

        return [
            'rewards_available' => $country === 'NG',
            'country_code' => $country,
            'reason' => match ($country) {
                'NG' => 'available',
                null => 'country_unavailable',
                default => 'unsupported_country',
            },
        ];
    }
}