<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudent
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isStudent()) {
            return response()->json(['message' => 'This page is for students only.'], 403);
        }

        return $next($request);
    }
}
