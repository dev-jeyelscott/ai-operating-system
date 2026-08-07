<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Domain\Events\DeadLetterSource;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Authorizes and validates one project dead-letter replay.
 */
final class ReplayProjectDeadLetterRequest extends FormRequest
{
    /**
     * Restrict recovery mutation to project approvers.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && $this->user()?->can('approve', $project) === true;
    }

    /**
     * Return the safe replay input contract.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'source' => [
                'required',
                'string',
                Rule::enum(DeadLetterSource::class),
            ],
            'identifier' => [
                'required',
                'string',
                'max:191',
            ],
            'reason' => [
                'required',
                'string',
                'min:10',
                'max:1000',
            ],
        ];
    }

    /**
     * Return the validated dead-letter source.
     */
    public function source(): DeadLetterSource
    {
        $source = DeadLetterSource::tryFrom(
            (string) $this->validated('source'),
        );

        if (! $source instanceof DeadLetterSource) {
            throw new LogicException(
                'The validated dead-letter source is invalid.',
            );
        }

        return $source;
    }

    /**
     * Return the validated dead-letter identifier.
     */
    public function identifier(): string
    {
        return trim((string) $this->validated('identifier'));
    }

    /**
     * Return the validated and auditable replay reason.
     */
    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
