<?php

namespace App\Http\Controllers\Api\Officer;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\Organization;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $announcements = $this->organization($request)
            ->announcements()
            ->with(['organization', 'author'])
            ->orderByDesc('is_pinned')
            ->latest('published_at')
            ->get();

        return response()->json(AnnouncementResource::collection($announcements));
    }

    public function store(AnnouncementRequest $request): JsonResponse
    {
        $organization = $this->organization($request);

        $announcement = $organization->announcements()->create([
            'user_id' => $request->user()->id,
            'title' => $request->input('title'),
            'body' => $request->input('body'),
            'audience' => $request->input('audience', 'members'),
            'is_pinned' => $request->boolean('is_pinned'),
            'published_at' => now(),
        ]);

        if ($request->boolean('notify', true)) {
            $this->notifications->toMembers(
                $organization,
                'announcement',
                "{$organization->acronym}: {$announcement->title}",
                str($announcement->body)->limit(120)->toString(),
                '/announcements',
                $request->user()->id
            );
        }

        return response()->json(new AnnouncementResource($announcement->load(['organization', 'author'])), 201);
    }

    public function update(AnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        $this->guard($request, $announcement);

        $announcement->update([
            'title' => $request->input('title'),
            'body' => $request->input('body'),
            'audience' => $request->input('audience', $announcement->audience),
            'is_pinned' => $request->boolean('is_pinned'),
        ]);

        return response()->json(new AnnouncementResource($announcement->load(['organization', 'author'])));
    }

    public function destroy(Request $request, Announcement $announcement): JsonResponse
    {
        $this->guard($request, $announcement);
        $announcement->delete();

        return response()->json(['message' => 'Announcement deleted.']);
    }

    private function guard(Request $request, Announcement $announcement): void
    {
        abort_unless($announcement->organization_id === $this->organization($request)->id, 404);
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
