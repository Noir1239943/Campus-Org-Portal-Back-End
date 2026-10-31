<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Approve / reject with an optional note (applications, excuses, events). */
class ReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
