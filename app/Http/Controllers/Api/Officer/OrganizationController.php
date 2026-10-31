<?php

namespace App\Http\Controllers\Api\Officer;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationSettingsRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Officers edit their organization's public profile and membership settings.
 * Name, acronym, category and recognition status are managed by the OSA.
 */
class OrganizationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $organization = $this->organization($request)->loadCount('activeMemberships as members_count');

        return response()->json(new OrganizationResource($organization));
    }

    public function update(OrganizationSettingsRequest $request): JsonResponse
    {
        $organization = $this->organization($request);

        $organization->update([
            ...$request->safe()->only([
                'tagline', 'description', 'about', 'contact_email', 'facebook_url',
                'meeting_schedule', 'capacity', 'application_deadline', 'application_question',
            ]),
            'color' => $request->input('color', $organization->color),
            'tags' => array_values(array_filter(array_map('trim', $request->input('tags', [])))),
            'accepting_members' => $request->boolean('accepting_members'),
        ]);

        return response()->json(new OrganizationResource($organization->loadCount('activeMemberships as members_count')));
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
