<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrganizationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tagline' => ['nullable', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:400'],
            'about' => ['nullable', 'string', 'max:3000'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'meeting_schedule' => ['nullable', 'string', 'max:160'],
            'tags' => ['nullable', 'array', 'max:8'],
            'tags.*' => ['string', 'max:30'],
            'accepting_members' => ['boolean'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'application_deadline' => ['nullable', 'date'],
            'application_question' => ['nullable', 'string', 'max:255'],
        ];
    }
}
