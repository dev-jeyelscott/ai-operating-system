<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use App\Application\Audit\Data\AuditTimelineCriteria;
use App\Application\Audit\Data\AuditTimelineOrder;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates project-scoped execution and audit timeline filters.
 */
final class ProjectAuditTimelineRequest extends FormRequest
{
    /**
     * Authorization is enforced by the project policy on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Return the supported execution and audit filter validation rules.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'execution' => [
                'nullable',
                'string',
                'ulid',
            ],
            'event_type' => [
                'nullable',
                Rule::enum(AuditEventType::class),
            ],
            'subject_type' => [
                'nullable',
                'required_with:subject_id',
                Rule::enum(AuditSubjectType::class),
            ],
            'subject_id' => [
                'nullable',
                'string',
                'max:191',
            ],
            'correlation_id' => [
                'nullable',
                'string',
                'max:128',
            ],
            'causation_id' => [
                'nullable',
                'string',
                'max:128',
            ],
            'order' => [
                'nullable',
                Rule::enum(AuditTimelineOrder::class),
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:10',
                'max:100',
            ],
            'cursor' => [
                'nullable',
                'string',
                'max:2048',
            ],
        ];
    }

    /**
     * Build the immutable tenant-scoped application query criteria.
     */
    public function criteria(
        int $organizationId,
        int $projectId,
    ): AuditTimelineCriteria {
        $validated = $this->validated();

        return new AuditTimelineCriteria(
            organizationId: $organizationId,
            projectId: $projectId,
            executionId: $this->optionalString(
                validated: $validated,
                key: 'execution',
            ),
            correlationId: $this->optionalString(
                validated: $validated,
                key: 'correlation_id',
            ),
            causationId: $this->optionalString(
                validated: $validated,
                key: 'causation_id',
            ),
            eventType: $this->eventType($validated),
            subjectType: $this->subjectType($validated),
            subjectId: $this->optionalString(
                validated: $validated,
                key: 'subject_id',
            ),
            order: $this->timelineOrder($validated),
            perPage: isset($validated['per_page'])
                ? (int) $validated['per_page']
                : 25,
            cursor: $this->optionalString(
                validated: $validated,
                key: 'cursor',
            ),
        );
    }

    /**
     * Resolve the optional event type enum.
     *
     * @param  array<string, mixed>  $validated
     */
    private function eventType(array $validated): ?AuditEventType
    {
        $value = $this->optionalString($validated, 'event_type');

        return $value === null
            ? null
            : AuditEventType::from($value);
    }

    /**
     * Resolve the optional subject type enum.
     *
     * @param  array<string, mixed>  $validated
     */
    private function subjectType(array $validated): ?AuditSubjectType
    {
        $value = $this->optionalString($validated, 'subject_type');

        return $value === null
            ? null
            : AuditSubjectType::from($value);
    }

    /**
     * Resolve the requested deterministic timeline order.
     *
     * @param  array<string, mixed>  $validated
     */
    private function timelineOrder(array $validated): AuditTimelineOrder
    {
        $value = $this->optionalString($validated, 'order');

        return $value === null
            ? AuditTimelineOrder::NewestFirst
            : AuditTimelineOrder::from($value);
    }

    /**
     * Return one normalized optional string from validated input.
     *
     * @param  array<string, mixed>  $validated
     */
    private function optionalString(
        array $validated,
        string $key,
    ): ?string {
        $value = $validated[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
