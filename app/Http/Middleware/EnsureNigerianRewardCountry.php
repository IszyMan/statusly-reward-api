<?php

namespace App\Http\Middleware;

use App\Services\RewardCountryService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNigerianRewardCountry
{
    public function __construct(
        private readonly RewardCountryService $countries
    ) {
    }

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $availability = $this->countries->availability($request);

        if (! $availability['rewards_available']) {
            $unknown = $availability['country_code'] === null;

            return response()
                ->json([
                    ...$availability,
                    'message' => $unknown
                        ? 'We could not verify rewards availability. Please try again.'
                        : 'Reward offers are currently available only in Nigeria.',
                ], $unknown ? 503 : 403)
                ->header('Cache-Control', 'no-store, private');
        }

        $response = $next($request);

        $response->headers->set(
            'Cache-Control',
            'no-store, private'
        );

        return $response;
    }
}