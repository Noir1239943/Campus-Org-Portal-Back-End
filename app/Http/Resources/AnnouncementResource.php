<?php

namespace App\Http\Resources;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Announcement */
class AnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'audience' => $this->audience,
            'is_pinned' => $this->is_pinned,
            'published_at' => $this->published_at?->toIso8601String(),
            'organization' => $this->whenLoaded('organization', fn () => OrganizationResource::brief($this->organization)),
            'author' => $this->whenLoaded('author', fn () => $this->author ? [
                'name' => $this->author->name,
                'title' => $this->author->title,
            ] : null),
        ];
    }
}
