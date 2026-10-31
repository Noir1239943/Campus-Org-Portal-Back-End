<?php

namespace App\Http\Controllers\Api\Adviser;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Campus-wide announcements from the Office of Student Affairs. */
class AnnouncementController extends Controller
{
    public function index(): JsonResponse
    {
        $announcements = Announcement::query()
            ->whereNull('organization_id')
            ->with(['organization', 'author'])
            ->orderByDesc('is_pinned')
            ->latest('published_at')
            ->get();

        return response()->json(AnnouncementResource::collection($announcements));
    }

    public function store(AnnouncementRequest $request, NotificationService $notifications): JsonResponse
    {
        $announcement = Announcement::query()->create([
            'organization_id' => null,
            'user_id' => $request->user()->id,
            'title' => $request->input('title'),
            'body' => $request->input('body'),
            'audience' => 'all',
            'is_pinned' => $request->boolean('is_pinned'),
            'published_at' => now(),
        ]);

        if ($request->boolean('notify', true)) {
            $notifications->toAllStudents(
                'announcement',
                'OSA: '.$announcement->title,
                str($announcement->body)->limit(120)->toString(),
                '/announcements'
            );
        }

        return response()->json(new AnnouncementResource($announcement->load(['organization', 'author'])), 201);
    }

    public function update(AnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        abort_unless($announcement->organization_id === null, 404);

        $announcement->update([
            'title' => $request->input('title'),
            'body' => $request->input('body'),
            'is_pinned' => $request->boolean('is_pinned'),
        ]);

        return response()->json(new AnnouncementResource($announcement->load(['organization', 'author'])));
    }

    public function destroy(Request $request, Announcement $announcement): JsonResponse
    {
        abort_unless($announcement->organization_id === null, 404);
        $announcement->delete();

        return response()->json(['message' => 'Announcement deleted.']);
    }
}
