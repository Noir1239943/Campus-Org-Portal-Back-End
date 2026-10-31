<?php

namespace App\Http\Requests;

use App\Models\ExcuseRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExcuseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(array_keys(ExcuseRequest::REASONS))],
            'details' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'details.min' => 'Add a short explanation (at least 10 characters) so the officers can review it.',
        ];
    }
}
