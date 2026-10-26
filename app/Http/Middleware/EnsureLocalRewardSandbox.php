<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLocalRewardSandbox
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            ! app()->environment('local', 'testing')
            || config('vtpass.environment') !== 'sandbox'
        ) {
            return response()->json([
                'message' => 'Local sandbox endpoint unavailable.',
            ], 403);
        }

        $response = $next($request);

        $response->headers->set(
            'Cache-Control',
            'no-store, private'
        );

        return $response;
    }
}