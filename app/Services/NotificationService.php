<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserNotification;

/**
 * In-app notifications (the bell icon). Kept simple so it works on free
 * hosting without a mail server or queue worker.
 */
class NotificationService
{
    /**
     * @param  User|int|iterable<int|User>  $users
     */
    public function send(User|int|iterable $users, string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        $ids = collect(is_iterable($users) ? $users : [$users])
            ->map(fn ($u) => $u instanceof User ? $u->id : (int) $u)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $now = now();

        $ids->chunk(500)->each(function ($chunk) use ($type, $title, $body, $link, $now) {
            UserNotification::query()->insert($chunk->map(fn ($id) => [
                'user_id' => $id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'link' => $link,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });
    }

    public function toOfficers(Organization $organization, string $type, string $title, ?string $body = null, ?string $link = null, ?int $exceptUserId = null): void
    {
        $ids = $organization->officerMemberships()->pluck('user_id')->reject(fn ($id) => $id === $exceptUserId);

        $this->send($ids, $type, $title, $body, $link);
    }

    public function toMembers(Organization $organization, string $type, string $title, ?string $body = null, ?string $link = null, ?int $exceptUserId = null): void
    {
        $ids = Membership::query()
            ->where('organization_id', $organization->id)
            ->where('status', Membership::STATUS_ACTIVE)
            ->pluck('user_id')
            ->reject(fn ($id) => $id === $exceptUserId);

        $this->send($ids, $type, $title, $body, $link);
    }

    public function toAdvisers(string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        $this->send(User::query()->where('role', User::ROLE_ADVISER)->pluck('id'), $type, $title, $body, $link);
    }

    public function toAllStudents(string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        $this->send(User::query()->students()->pluck('id'), $type, $title, $body, $link);
    }

    /** Tell members about a newly published event. */
    public function eventPublished(Event $event): void
    {
        $organization = $event->organization;

        $this->toMembers(
            $organization,
            'event_published',
            "New {$organization->acronym} event",
            "\"{$event->title}\" — {$event->starts_at->format('D, M j · g:i A')} at {$event->venue}.",
            "/events/{$event->id}"
        );
    }
}
