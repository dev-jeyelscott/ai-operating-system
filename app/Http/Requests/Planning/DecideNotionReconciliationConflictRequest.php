<?php

namespace App\Http\Requests\Planning;

use Illuminate\Foundation\Http\FormRequest;

final class DecideNotionReconciliationConflictRequest extends FormRequest
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
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['expected_fingerprint' => ['required', 'string', 'size:64', 'regex:/\A[0-9a-f]{64}\z/'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:191', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/']];
    }
}
