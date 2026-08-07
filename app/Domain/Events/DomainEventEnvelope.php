<?php

declare(strict_types=1);

namespace App\Domain\Events;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;

/**
 * Carries one versioned domain event with stable trace and tenant metadata.
 */
final readonly class DomainEventEnvelope implements JsonSerializable
{
    private const MAX_IDENTIFIER_LENGTH = 191;

    private const MAX_TRACE_IDENTIFIER_LENGTH = 128;

    public string $eventId;

    public string $eventName;

    public string $aggregateType;

    public string $aggregateId;

    public int $organizationId;

    public ?int $projectId;

    public DomainEventActor $actor;

    public ?string $provider;

    public CarbonImmutable $occurredAt;

    public string $correlationId;

    public ?string $causationId;

    public ?string $executionId;

    public int $schemaVersion;

    /** @var array<string, mixed> */
    public array $payload;

    /**
     * Validate and store the complete canonical domain-event envelope.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(
        string $eventId,
        string $eventName,
        string $aggregateType,
        string $aggregateId,
        int $organizationId,
        ?int $projectId,
        DomainEventActor $actor,
        ?string $provider,
        CarbonImmutable $occurredAt,
        string $correlationId,
        ?string $causationId,
        ?string $executionId,
        int $schemaVersion,
        array $payload,
    ) {
        if (! Str::isUlid($eventId)) {
            throw new InvalidArgumentException(
                'The domain event identifier must be a valid ULID.',
            );
        }

        if ($organizationId < 1) {
            throw new InvalidArgumentException(
                'The domain event organization identifier must be positive.',
            );
        }

        if ($projectId !== null && $projectId < 1) {
            throw new InvalidArgumentException(
                'The domain event project identifier must be positive.',
            );
        }

        $this->eventId = $eventId;
        $this->eventName = $this->normalizeEventName($eventName);
        $this->aggregateType = $this->normalizeAggregateType($aggregateType);
        $this->aggregateId = $this->normalizeIdentifier(
            $aggregateId,
            'aggregate',
        );
        $this->organizationId = $organizationId;
        $this->projectId = $projectId;
        $this->actor = $actor;
        $this->provider = $this->normalizeOptionalProvider($provider);
        $this->occurredAt = $occurredAt->utc();
        $this->correlationId = $this->normalizeRequiredTraceId(
            $correlationId,
            'correlation',
        );
        $this->causationId = $this->normalizeOptionalTraceId(
            $causationId,
            'causation',
        );
        $this->executionId = $this->normalizeOptionalTraceId(
            $executionId,
            'execution',
        );
        $this->schemaVersion = EventSchemaVersion::fromInt(
            $schemaVersion,
        )->value;
        $this->payload = $this->validatePayload($payload);
    }

    /**
     * Serialize the envelope into the stable persistence and transport shape.
     *
     * @return array{
     *     event_id: string,
     *     event_name: string,
     *     aggregate_type: string,
     *     aggregate_id: string,
     *     organization_id: int,
     *     project_id: int|null,
     *     actor: array{type: string, id: string},
     *     provider: string|null,
     *     occurred_at: string,
     *     correlation_id: string,
     *     causation_id: string|null,
     *     execution_id: string|null,
     *     schema_version: int,
     *     payload: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_name' => $this->eventName,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'actor' => $this->actor->toArray(),
            'provider' => $this->provider,
            'occurred_at' => $this->occurredAt->toISOString(),
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
            'execution_id' => $this->executionId,
            'schema_version' => $this->schemaVersion,
            'payload' => $this->payload,
        ];
    }

    /**
     * Return the canonical JSON representation through JsonSerializable.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Normalize a stable dotted event name.
     */
    private function normalizeEventName(string $eventName): string
    {
        $normalized = trim($eventName);

        if (
            preg_match(
                '/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+\z/',
                $normalized,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'The domain event name must use lowercase dotted notation.',
            );
        }

        return $normalized;
    }

    /**
     * Normalize a stable snake-case aggregate type.
     */
    private function normalizeAggregateType(string $aggregateType): string
    {
        $normalized = trim($aggregateType);

        if (
            preg_match(
                '/\A[a-z][a-z0-9_]{0,127}\z/',
                $normalized,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'The domain event aggregate type must use lowercase snake case.',
            );
        }

        return $normalized;
    }

    /**
     * Normalize a required aggregate identifier.
     */
    private function normalizeIdentifier(
        string $identifier,
        string $name,
    ): string {
        $normalized = trim($identifier);

        if ($normalized === '') {
            throw new InvalidArgumentException(
                "The domain event {$name} identifier is required.",
            );
        }

        if (mb_strlen($normalized) > self::MAX_IDENTIFIER_LENGTH) {
            throw new InvalidArgumentException(
                "The domain event {$name} identifier may not exceed 191 characters.",
            );
        }

        return $normalized;
    }

    /**
     * Normalize the optional execution-provider identifier.
     */
    private function normalizeOptionalProvider(?string $provider): ?string
    {
        if ($provider === null) {
            return null;
        }

        $normalized = trim($provider);

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
                'The domain event provider identifier is invalid.',
            );
        }

        return $normalized;
    }

    /**
     * Normalize a required correlation identifier.
     */
    private function normalizeRequiredTraceId(
        string $identifier,
        string $name,
    ): string {
        $normalized = trim($identifier);

        if ($normalized === '') {
            throw new InvalidArgumentException(
                "The domain event {$name} identifier is required.",
            );
        }

        $this->assertTraceIdIsValid($normalized, $name);

        return $normalized;
    }

    /**
     * Normalize an optional causation or execution identifier.
     */
    private function normalizeOptionalTraceId(
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

        $this->assertTraceIdIsValid($normalized, $name);

        return $normalized;
    }

    /**
     * Validate one trace identifier against the canonical safe character set.
     */
    private function assertTraceIdIsValid(
        string $identifier,
        string $name,
    ): void {
        if (
            strlen($identifier) > self::MAX_TRACE_IDENTIFIER_LENGTH
            || preg_match(
                '/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/',
                $identifier,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                "The domain event {$name} identifier is invalid.",
            );
        }
    }

    /**
     * Ensure the payload is object-shaped and JSON serializable.
     *
     * Integer keys are rejected so the serialized payload always has a stable
     * JSON-object shape instead of unexpectedly becoming a JSON array.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload): array
    {
        /** @var array<string, mixed> $validatedPayload */
        $validatedPayload = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    'The domain event payload must use string keys.',
                );
            }

            $validatedPayload[$key] = $value;
        }

        try {
            json_encode(
                $validatedPayload,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'The domain event payload must be JSON serializable.',
                previous: $exception,
            );
        }

        return $validatedPayload;
    }
}
