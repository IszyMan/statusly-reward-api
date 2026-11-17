<?php

namespace App\Http\Middleware;

use App\Services\RewardRuntimeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLocalRewardSandbox
{
    public function __construct(
        private readonly RewardRuntimeService $runtime
    ) {
    }

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        if (! $this->runtime->available()) {
            return response()
                ->json([
                    'message' => 'Rewards are temporarily unavailable. '
                        .'Please try again later.',
                ], 503)
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