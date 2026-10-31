<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Disabled accounts cannot keep using tokens that were issued before they were disabled. */
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            $user->currentAccessToken()?->delete();

            return response()->json(['message' => 'This account is disabled. Please visit the Office of Student Affairs.'], 401);
        }

        return $next($request);
    }
}
