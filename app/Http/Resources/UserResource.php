<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'id_number' => $this->id_number,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'initials' => $this->initials,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'program' => $this->program,
            'year_level' => $this->year_level,
            'section' => $this->section,
            'program_label' => $this->program_label,
            'phone' => $this->phone,
            'title' => $this->title,
        ];
    }

    /** Small version used inside other resources. */
    public static function brief(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'id_number' => $user->id_number,
            'name' => $user->name,
            'initials' => $user->initials,
            'email' => $user->email,
            'program_label' => $user->program_label,
        ];
    }
}
