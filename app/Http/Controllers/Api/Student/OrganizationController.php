<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementResource;
use App\Http\Resources\EventResource;
use App\Http\Resources\OrganizationResource;
use App\Models\Event;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\Organization;
use App\Models\User;
use App\Support\StudentEventPresenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    /** Campus directory with search and filters. */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:40'],
            'accepting' => ['nullable', 'boolean'],
            'saved' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        $organizations = Organization::query()
            ->visible()
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.strtolower(trim((string) $request->input('search'))).'%';
                $q->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(acronym) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(description) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(category) LIKE ?', [$term]);
                });
            })
            ->when($request->filled('category'), fn ($q) => $q->where('category', (string) $request->input('category')))
            ->when($request->boolean('accepting'), fn ($q) => $q->where('accepting_members', true)->where('requires_all_students', false))
            ->when($request->boolean('saved'), fn ($q) => $q->whereIn('id', $user->savedOrganizations()->pluck('organizations.id')))
            ->withCount([
                'activeMemberships as members_count',
                'events as upcoming_events_count' => fn ($q) => $q->published()->where('ends_at', '>=', now()),
            ])
            ->orderByDesc('requires_all_students')
            ->orderBy('name')
            ->get();

        $this->attachViewerState($organizations, $user);

        return response()->json([
            'data' => OrganizationResource::collection($organizations),
            'total' => Organization::query()->visible()->count(),
            'categories' => Organization::CATEGORIES,
        ]);
    }

    /** Organization profile page. */
    public function show(Request $request, Organization $organization): JsonResponse
    {
        abort_if($organization->status === Organization::STATUS_INACTIVE, 404);

        $user = $request->user();

        $organization->loadCount([
            'activeMemberships as members_count',
            'activeMemberships as new_members_count' => fn ($q) => $q->where('joined_at', '>=', now()->subMonths(4)),
            'events as upcoming_events_count' => fn ($q) => $q->published()->where('ends_at', '>=', now()),
            'events as events_count' => fn ($q) => $q->published()->where('ends_at', '<', now()),
        ]);

        $this->attachViewerState(new Collection([$organization]), $user);

        $presenter = new StudentEventPresenter($user);
        $events = $organization->events()
            ->published()
            ->upcoming()
            ->with('organization')
            ->withCount('registrations')
            ->limit(5)
            ->get();
        $presenter->decorateMany($events);

        $officers = $organization->officerMemberships()
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(fn (Membership $m) => [
                'name' => $m->user->name,
                'initials' => $m->user->initials,
                'position' => $m->position,
            ]);

        $isMember = $user->isMemberOf($organization->id);

        $announcements = $organization->announcements()
            ->whereNotNull('published_at')
            ->when(! $isMember, fn ($q) => $q->where('audience', 'all'))
            ->with(['organization', 'author'])
            ->latest('published_at')
            ->limit(3)
            ->get();

        return response()->json([
            'organization' => new OrganizationResource($organization),
            'upcoming_events' => EventResource::collection($events),
            'officers' => $officers,
            'announcements' => AnnouncementResource::collection($announcements),
        ]);
    }

    /** Save / unsave an organization (bookmark). */
    public function toggleSave(Request $request, Organization $organization): JsonResponse
    {
        $result = $request->user()->savedOrganizations()->toggle($organization->id);

        return response()->json(['saved' => in_array($organization->id, $result['attached'])]);
    }

    /** Organizations I belong to, with my position. */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        $organizations = Organization::query()
            ->whereIn('id', $user->activeOrganizationIds() ?: [0])
            ->withCount([
                'activeMemberships as members_count',
                'events as upcoming_events_count' => fn ($q) => $q->published()->where('ends_at', '>=', now()),
            ])
            ->orderBy('name')
            ->get();

        $this->attachViewerState($organizations, $user);

        $saved = $user->savedOrganizations()
            ->withCount('activeMemberships as members_count')
            ->get();
        $this->attachViewerState($saved, $user);

        return response()->json([
            'memberships' => OrganizationResource::collection($organizations),
            'saved' => OrganizationResource::collection($saved),
        ]);
    }

    /**
     * Membership, latest application and bookmark for the logged-in student.
     *
     * @param  Collection<int, Organization>  $organizations
     */
    private function attachViewerState(Collection $organizations, User $user): void
    {
        $ids = $organizations->pluck('id')->all();

        $memberships = Membership::query()
            ->where('user_id', $user->id)
            ->whereIn('organization_id', $ids ?: [0])
            ->where('status', Membership::STATUS_ACTIVE)
            ->get()
            ->keyBy('organization_id');

        $applications = MembershipApplication::query()
            ->where('user_id', $user->id)
            ->whereIn('organization_id', $ids ?: [0])
            ->latest('id')
            ->get()
            ->unique('organization_id')
            ->keyBy('organization_id');

        $saved = $user->savedOrganizations()->pluck('organizations.id')->flip();

        foreach ($organizations as $organization) {
            $membership = $memberships->get($organization->id);
            $application = $applications->get($organization->id);

            $organization->setAttribute('viewer_state', [
                'membership' => $membership ? [
                    'position' => $membership->position,
                    'is_officer' => $membership->is_officer,
                    'joined_at' => $membership->joined_at?->toIso8601String(),
                ] : null,
                'application' => $application ? [
                    'id' => $application->id,
                    'reference' => $application->reference,
                    'status' => $application->status,
                ] : null,
                'is_saved' => $saved->has($organization->id),
            ]);
        }
    }
}
