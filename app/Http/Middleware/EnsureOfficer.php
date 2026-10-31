<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Officer routes act on one organization at a time. The frontend sends the
 * selected organization in the X-Organization-Id header (an officer can
 * hold positions in more than one organization).
 */
class EnsureOfficer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $memberships = $user->officerMemberships()->get(['organization_id']);

        if ($memberships->isEmpty()) {
            return response()->json(['message' => 'Only organization officers can use this page.'], 403);
        }

        $requested = (int) $request->header('X-Organization-Id');
        $organizationId = $memberships->contains('organization_id', $requested)
            ? $requested
            : $memberships->first()->organization_id;

        $request->attributes->set('organization', Organization::query()->findOrFail($organizationId));

        return $next($request);
    }
}
