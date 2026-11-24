<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * F1 – Pangalawang bantay: kahit may natitirang token, tatanggihan ang bawat
 * request ng user na can_login = false, at buburahin ang token na ginamit.
 * (Ang una: binubura na ng User model ang tokens kapag naging false ang can_login.)
 */
class EnsureCanLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->can_login) {
            $user->currentAccessToken()?->delete();

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
