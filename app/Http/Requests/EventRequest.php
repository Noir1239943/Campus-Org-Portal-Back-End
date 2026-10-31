<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(Event::TYPES)],
            'description' => ['nullable', 'string', 'max:3000'],
            'venue' => ['required', 'string', 'max:150'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'visibility' => ['required', Rule::in([Event::VISIBILITY_OPEN, Event::VISIBILITY_MEMBERS])],
            'is_mandatory' => ['boolean'],
            'service_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'speaker' => ['nullable', 'string', 'max:150'],
            // "draft" keeps it private, "submit" sends it for approval / publishing.
            'action' => ['nullable', Rule::in(['draft', 'submit'])],
        ];
    }

    public function messages(): array
    {
        return [
            'ends_at.after' => 'The end time must be after the start time.',
        ];
    }
}
