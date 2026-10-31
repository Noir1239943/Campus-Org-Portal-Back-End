<?php

namespace App\Http\Resources;

use App\Models\OrganizationProposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrganizationProposal */
class ProposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'name' => $this->name,
            'acronym' => $this->acronym,
            'category' => $this->category,
            'description' => $this->description,
            'purpose' => $this->purpose,
            'planned_activities' => $this->planned_activities,
            'adviser_name' => $this->adviser_name,
            'expected_members' => $this->expected_members,
            'status' => $this->status,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'proposer' => $this->relationLoaded('user') ? UserResource::brief($this->user) : null,
            'reviewer' => $this->relationLoaded('reviewer') ? $this->reviewer?->name : null,
            'organization' => $this->relationLoaded('organization') ? OrganizationResource::brief($this->organization) : null,
        ];
    }
}
