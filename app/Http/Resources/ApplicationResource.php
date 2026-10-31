<?php

namespace App\Http\Resources;

use App\Models\MembershipApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MembershipApplication */
class ApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'reason' => $this->reason,
            'experience' => $this->experience,
            'availability' => $this->availability,
            'answer' => $this->answer,
            'submitted_at' => $this->created_at?->toIso8601String(),
            'eligibility_verified_at' => $this->eligibility_verified_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note,
            'organization' => $this->whenLoaded('organization', fn () => array_merge(
                OrganizationResource::brief($this->organization),
                ['application_question' => $this->organization->application_question]
            )),
            'applicant' => $this->whenLoaded('user', fn () => UserResource::brief($this->user)),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
        ];
    }
}
