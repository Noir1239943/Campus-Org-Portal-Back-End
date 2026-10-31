<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdviser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdviser()) {
            return response()->json(['message' => 'This page is for faculty advisers and the Office of Student Affairs.'], 403);
        }

        return $next($request);
    }
}
