<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Event */
class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'description' => $this->description,
            'venue' => $this->venue,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'capacity' => $this->capacity,
            'visibility' => $this->visibility,
            'is_mandatory' => $this->is_mandatory,
            'service_hours' => $this->service_hours,
            'speaker' => $this->speaker,
            'status' => $this->status,
            'display_status' => $this->displayStatus(),
            'review_note' => $this->review_note,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'check_in_open' => $this->isCheckInOpen(),
            'organization' => $this->whenLoaded('organization', fn () => OrganizationResource::brief($this->organization)),
            'creator' => $this->whenLoaded('creator', fn () => UserResource::brief($this->creator)),
            'registrations_count' => $this->whenCounted('registrations_count'),
            // Set by controllers: attendance numbers (officer/adviser views).
            'stats' => $this->when(array_key_exists('stats', $attributes), fn () => $attributes['stats']),
            // Set by controllers: the logged-in student's registration/attendance.
            'viewer' => $this->when(array_key_exists('viewer_state', $attributes), fn () => $attributes['viewer_state']),
        ];
    }

    public static function brief(?Event $event): ?array
    {
        if (! $event) {
            return null;
        }

        return [
            'id' => $event->id,
            'title' => $event->title,
            'type' => $event->type,
            'venue' => $event->venue,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'is_mandatory' => $event->is_mandatory,
            'display_status' => $event->displayStatus(),
            'organization' => $event->relationLoaded('organization') ? OrganizationResource::brief($event->organization) : null,
        ];
    }
}
