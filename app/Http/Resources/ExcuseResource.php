<?php

namespace App\Http\Resources;

use App\Models\ExcuseRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExcuseRequest */
class ExcuseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason,
            'reason_label' => ExcuseRequest::REASONS[$this->reason] ?? $this->reason,
            'details' => $this->details,
            'status' => $this->status,
            'submitted_at' => $this->created_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note,
            'event' => $this->whenLoaded('event', fn () => EventResource::brief($this->event)),
            'student' => $this->whenLoaded('user', fn () => UserResource::brief($this->user)),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
        ];
    }
}
