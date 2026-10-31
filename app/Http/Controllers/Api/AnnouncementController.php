<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Announcement feed for students: campus-wide + my organizations + public posts. */
class AnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['organization_id' => ['nullable', 'integer']]);

        $announcements = Announcement::query()
            ->visibleTo($request->user())
            ->when($request->filled('organization_id'), fn ($q) => $q->where('organization_id', $request->integer('organization_id')))
            ->with(['organization', 'author'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->limit(50)
            ->get();

        return response()->json(AnnouncementResource::collection($announcements));
    }
}
