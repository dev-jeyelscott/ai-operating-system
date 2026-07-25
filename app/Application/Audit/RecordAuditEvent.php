<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Application\Audit\Contracts\AuditEventRepository;
use App\Application\Audit\Data\AuditEventData;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * Validates and appends one application audit event.
 */
final readonly class RecordAuditEvent
{
    private const MAX_IDENTIFIER_LENGTH = 191;

    private const MAX_METADATA_BYTES = 16_384;

    /**
     * Inject the append-only audit persistence contract.
     */
    public function __construct(
        private AuditEventRepository $auditEvents,
    ) {}

    /**
     * Create and append a validated authoritative event.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        int $organizationId,
        ?int $projectId,
        AuditActorType $actorType,
        string $actorId,
        AuditEventType $eventType,
        AuditSubjectType $subjectType,
        string $subjectId,
        ?string $correlationId = null,
        array $metadata = [],
        ?string $causationId = null,
        ?string $executionId = null,
        int $schemaVersion = 1,
        ?string $deduplicationKey = null,
    ): AuditEventData {
        if ($organizationId < 1) {
            throw new InvalidArgumentException(
                'The audit organization identifier must be positive.',
            );
        }

        if ($projectId !== null && $projectId < 1) {
            throw new InvalidArgumentException(
                'The audit project identifier must be positive.',
            );
        }

        if ($schemaVersion < 1) {
            throw new InvalidArgumentException(
                'The audit schema version must be positive.',
            );
        }

        $event = new AuditEventData(
            eventId: (string) Str::ulid(),
            organizationId: $organizationId,
            projectId: $projectId,
            actorType: $actorType,
            actorId: $this->normalizeIdentifier($actorId, 'actor'),
            eventType: $eventType,
            subjectType: $subjectType,
            subjectId: $this->normalizeIdentifier($subjectId, 'subject'),
            correlationId: $this->normalizeTraceId(
                $correlationId,
                'correlation',
            ),
            causationId: $this->normalizeTraceId(
                $causationId,
                'causation',
            ),
            executionId: $this->normalizeTraceId(
                $executionId,
                'execution',
            ),
            schemaVersion: $schemaVersion,
            deduplicationKey: $this->normalizeDeduplicationKey(
                $deduplicationKey,
            ),
            metadata: $this->validateMetadata($metadata),
            occurredAt: CarbonImmutable::now(),
        );

        $this->auditEvents->append($event);

        return $event;
    }

    /**
     * Normalize and validate an actor or subject identifier.
     */
    private function normalizeIdentifier(
        string $identifier,
        string $name,
    ): string {
        $normalized = trim($identifier);

        if ($normalized === '') {
            throw new InvalidArgumentException(
                "The audit {$name} identifier is required.",
            );
        }

        if (mb_strlen($normalized) > self::MAX_IDENTIFIER_LENGTH) {
            throw new InvalidArgumentException(
                "The audit {$name} identifier may not exceed "
                    .self::MAX_IDENTIFIER_LENGTH.' characters.',
            );
        }

        return $normalized;
    }

    /**
     * Validate correlation, causation, and execution identifiers.
     */
    private function normalizeTraceId(
        ?string $identifier,
        string $name,
    ): ?string {
        if ($identifier === null) {
            return null;
        }

        $normalized = trim($identifier);

        if ($normalized === '') {
            return null;
        }

        if (
            preg_match(
                '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/',
                $normalized,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                "The audit {$name} identifier is invalid.",
            );
        }

        return $normalized;
    }

    /**
     * Ensure metadata is bounded and JSON serializable before persistence.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function validateMetadata(array $metadata): array
    {
        try {
            $encoded = json_encode(
                $metadata,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Audit metadata must be JSON serializable.',
                previous: $exception,
            );
        }

        if (strlen($encoded) > self::MAX_METADATA_BYTES) {
            throw new InvalidArgumentException(
                'Audit metadata may not exceed 16 KiB.',
            );
        }

        return $metadata;
    }

    /**
     * Validate the optional tenant-scoped duplicate-prevention key.
     */
    private function normalizeDeduplicationKey(
        ?string $deduplicationKey,
    ): ?string {
        if ($deduplicationKey === null) {
            return null;
        }

        $normalized = trim($deduplicationKey);

        if ($normalized === '') {
            return null;
        }

        if (
            preg_match(
                '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\z/',
                $normalized,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'The audit deduplication key is invalid.',
            );
        }

        return $normalized;
    }
}
