<?php

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Student: propose a new organization. */
class OrganizationProposalRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('organizations', 'name')],
            'acronym' => ['required', 'string', 'max:12', 'regex:/^[A-Z0-9&\-]+$/', Rule::unique('organizations', 'acronym')],
            'category' => ['required', Rule::in(Organization::CATEGORIES)],
            'description' => ['required', 'string', 'min:20', 'max:400'],
            'purpose' => ['required', 'string', 'min:30', 'max:2000'],
            'planned_activities' => ['nullable', 'string', 'max:2000'],
            'adviser_name' => ['nullable', 'string', 'max:150'],
            'expected_members' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'An organization with this name already exists.',
            'acronym.unique' => 'That acronym is already used by another organization.',
            'acronym.regex' => 'Use letters, numbers, & or - only (no spaces).',
            'description.min' => 'Please describe the organization in at least 20 characters.',
            'purpose.min' => 'Tell the OSA why this organization is needed (at least 30 characters).',
        ];
    }
}
