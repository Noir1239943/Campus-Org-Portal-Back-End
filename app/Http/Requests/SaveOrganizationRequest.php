<?php

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** OSA: create or edit an organization's official details. */
class SaveOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('acronym'))) {
            $this->merge(['acronym' => strtoupper(trim($this->input('acronym')))]);
        }
    }

    public function rules(): array
    {
        $current = $this->route('organization');
        $ignore = $current instanceof Organization ? $current->id : null;

        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('organizations', 'name')->ignore($ignore)],
            'acronym' => ['required', 'string', 'max:12', 'regex:/^[A-Z0-9&\-]+$/', Rule::unique('organizations', 'acronym')->ignore($ignore)],
            'category' => ['required', Rule::in(Organization::CATEGORIES)],
            'tagline' => ['nullable', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:400'],
            'about' => ['nullable', 'string', 'max:3000'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'adviser_name' => ['nullable', 'string', 'max:150'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'meeting_schedule' => ['nullable', 'string', 'max:160'],
            'accepting_members' => ['boolean'],
            'requires_all_students' => ['boolean'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
            // Student ID number or email; only used when creating.
            'president' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'acronym.regex' => 'Use letters, numbers, & or - only (no spaces).',
        ];
    }

    /** Organization columns only (no president lookup). */
    public function organizationData(): array
    {
        $data = $this->safe()->except('president');

        if (empty($data['color'])) {
            unset($data['color']);
        }

        foreach (['accepting_members', 'requires_all_students'] as $flag) {
            if ($this->has($flag)) {
                $data[$flag] = $this->boolean($flag);
            }
        }

        return $data;
    }
}
