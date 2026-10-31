<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:20', 'max:1500'],
            'experience' => ['nullable', 'string', 'max:1500'],
            'availability' => ['nullable', 'string', 'max:255'],
            'answer' => ['nullable', 'string', 'max:1500'],
            'agreed' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Tell the officers a little more about why you want to join (at least 20 characters).',
            'agreed.accepted' => 'Please agree to follow the organization constitution and school policies.',
        ];
    }
}
