<?php

declare(strict_types=1);

namespace App\Http\Requests\Planning;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegenerateRoadmapRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_content_version' => ['required', 'integer', 'min:1'],
            'expected_fingerprint' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            'idempotency_key' => ['required', 'string', 'max:191', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/'],
            'feedback' => ['required', 'string', 'max:2000'],
        ];
    }
}
