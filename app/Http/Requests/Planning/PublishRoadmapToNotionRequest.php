<?php

declare(strict_types=1);

namespace App\Http\Requests\Planning;

use Illuminate\Foundation\Http\FormRequest;

final class PublishRoadmapToNotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['idempotency_key' => ['required', 'string', 'max:191', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/']];
    }
}
