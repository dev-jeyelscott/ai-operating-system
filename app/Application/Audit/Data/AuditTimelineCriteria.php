<?php

declare(strict_types=1);

namespace App\Application\Audit\Data;

use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use InvalidArgumentException;

/**
 * Immutable, tenant-scoped filters for one audit timeline query.
 */
final readonly class AuditTimelineCriteria
{
    public const DEFAULT_PER_PAGE = 50;

    public const MAX_PER_PAGE = 100;

    public int $organizationId;

    public ?int $projectId;

    public ?string $executionId;

    public ?string $correlationId;

    public ?string $causationId;

    public ?AuditEventType $eventType;

    public ?AuditSubjectType $subjectType;

    public ?string $subjectId;

    public AuditTimelineOrder $order;

    public int $perPage;

    public ?string $cursor;

    /**
     * Create and validate one audit timeline criteria object.
     */
    public function __construct(
        int $organizationId,
        ?int $projectId = null,
        ?string $executionId = null,
        ?string $correlationId = null,
        ?string $causationId = null,
        ?AuditEventType $eventType = null,
        ?AuditSubjectType $subjectType = null,
        ?string $subjectId = null,
        AuditTimelineOrder $order = AuditTimelineOrder::OldestFirst,
        int $perPage = self::DEFAULT_PER_PAGE,
        ?string $cursor = null,
    ) {
        if ($organizationId < 1) {
            throw new InvalidArgumentException(
                'The audit timeline organization identifier must be positive.',
            );
        }

        if ($projectId !== null && $projectId < 1) {
            throw new InvalidArgumentException(
                'The audit timeline project identifier must be positive.',
            );
        }

        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException(sprintf(
                'The audit timeline page size must be between 1 and %d.',
                self::MAX_PER_PAGE,
            ));
        }

        $normalizedSubjectId = $this->normalizeOptionalIdentifier(
            value: $subjectId,
            label: 'subject',
            maxLength: 191,
        );

        if ($normalizedSubjectId !== null && $subjectType === null) {
            throw new InvalidArgumentException(
                'The audit timeline subject type is required when filtering by subject identifier.',
            );
        }

        $this->organizationId = $organizationId;
        $this->projectId = $projectId;

        $this->executionId = $this->normalizeOptionalIdentifier(
            value: $executionId,
            label: 'execution',
            maxLength: 128,
        );

        $this->correlationId = $this->normalizeOptionalIdentifier(
            value: $correlationId,
            label: 'correlation',
            maxLength: 128,
        );

        $this->causationId = $this->normalizeOptionalIdentifier(
            value: $causationId,
            label: 'causation',
            maxLength: 128,
        );

        $this->eventType = $eventType;
        $this->subjectType = $subjectType;
        $this->subjectId = $normalizedSubjectId;
        $this->order = $order;
        $this->perPage = $perPage;
        $this->cursor = $this->normalizeCursor($cursor);
    }

    /**
     * Normalize one optional bounded query identifier.
     */
    private function normalizeOptionalIdentifier(
        ?string $value,
        string $label,
        int $maxLength,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > $maxLength) {
            throw new InvalidArgumentException(sprintf(
                'The audit timeline %s identifier may not exceed %d characters.',
                $label,
                $maxLength,
            ));
        }

        return $normalized;
    }

    /**
     * Normalize Laravel's optional opaque cursor token.
     */
    private function normalizeCursor(?string $cursor): ?string
    {
        if ($cursor === null) {
            return null;
        }

        $normalized = trim($cursor);

        return $normalized === '' ? null : $normalized;
    }
}
