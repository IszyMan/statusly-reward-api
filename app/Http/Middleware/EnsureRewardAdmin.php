<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRewardAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return redirect()->route('admin.login');
        }

        abort_unless(
            (bool) $request->user()->is_admin,
            403,
            'Administrator access required.'
        );

        $response = $next($request);

        $response->headers->set(
            'Cache-Control',
            'no-store, private'
        );

        return $response;
    }
}