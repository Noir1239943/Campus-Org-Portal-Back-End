<?php

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Organization */
class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'acronym' => $this->acronym,
            'category' => $this->category,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'about' => $this->about,
            'color' => $this->color,
            'adviser_name' => $this->adviser_name,
            'contact_email' => $this->contact_email,
            'facebook_url' => $this->facebook_url,
            'meeting_schedule' => $this->meeting_schedule,
            'tags' => $this->tags ?? [],
            'accepting_members' => $this->accepting_members,
            'is_open_for_applications' => $this->isOpenForApplications(),
            'capacity' => $this->capacity,
            'application_deadline' => $this->application_deadline?->toDateString(),
            'application_question' => $this->application_question,
            'requires_all_students' => $this->requires_all_students,
            'status' => $this->status,
            'recognized_at' => $this->recognized_at?->toDateString(),
            'members_count' => $this->whenCounted('members_count'),
            'upcoming_events_count' => $this->whenCounted('upcoming_events_count'),
            'events_count' => $this->whenCounted('events_count'),
            'new_members_count' => $this->whenCounted('new_members_count'),
            // Set by controllers for the logged-in student (membership, application, saved).
            'viewer' => $this->when(
                array_key_exists('viewer_state', $this->resource->getAttributes()),
                fn () => $this->resource->getAttributes()['viewer_state'] ?? null
            ),
        ];
    }

    public static function brief(?Organization $organization): ?array
    {
        if (! $organization) {
            return null;
        }

        return [
            'id' => $organization->id,
            'slug' => $organization->slug,
            'name' => $organization->name,
            'acronym' => $organization->acronym,
            'category' => $organization->category,
            'color' => $organization->color,
        ];
    }
}
