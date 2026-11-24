<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pinapayagan lang ang user na may isa sa mga role na nakalista.
 * Gamit: ->middleware('role:Admin,Purchasing Officer')
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole(...$roles)) {
            return response()->json([
                'message' => 'Hindi pinapayagan ang role mo para dito.',
            ], 403);
        }

        return $next($request);
    }
}
