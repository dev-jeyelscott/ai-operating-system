<?php

declare(strict_types=1);

namespace App\Application\Events\Data;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * Carries one sanitized, transport-neutral message for live UI projections.
 */
final readonly class RealTimeStreamMessage
{
    private const MAX_TRACE_IDENTIFIER_LENGTH = 128;

    public string $eventId;

    public string $eventName;

    public int $organizationId;

    public ?int $projectId;

    public CarbonImmutable $occurredAt;

    public string $correlationId;

    public ?string $executionId;

    public int $schemaVersion;

    /** @var array<string, mixed> */
    public array $data;

    /**
     * Validate and store one canonical real-time stream message.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function __construct(
        string $eventId,
        string $eventName,
        int $organizationId,
        ?int $projectId,
        CarbonImmutable $occurredAt,
        string $correlationId,
        ?string $executionId,
        int $schemaVersion,
        array $data,
    ) {
        if (! Str::isUlid($eventId)) {
            throw new InvalidArgumentException(
                'The real-time stream event identifier must be a valid ULID.',
            );
        }

        if ($organizationId < 1) {
            throw new InvalidArgumentException(
                'The real-time stream organization identifier must be positive.',
            );
        }

        if ($projectId !== null && $projectId < 1) {
            throw new InvalidArgumentException(
                'The real-time stream project identifier must be positive.',
            );
        }

        if ($schemaVersion < 1) {
            throw new InvalidArgumentException(
                'The real-time stream schema version must be positive.',
            );
        }

        $this->eventId = $eventId;
        $this->eventName = $this->normalizeEventName($eventName);
        $this->organizationId = $organizationId;
        $this->projectId = $projectId;
        $this->occurredAt = $occurredAt->utc();
        $this->correlationId = $this->normalizeRequiredTraceId(
            $correlationId,
            'correlation',
        );
        $this->executionId = $this->normalizeOptionalTraceId(
            $executionId,
            'execution',
        );
        $this->schemaVersion = $schemaVersion;
        $this->data = $this->validateData($data);
    }

    /**
     * Return the private Laravel channel name without its transport prefix.
     */
    public function channelName(): string
    {
        if ($this->projectId === null) {
            return sprintf(
                'organizations.%d.stream',
                $this->organizationId,
            );
        }

        return sprintf(
            'organizations.%d.projects.%d.stream',
            $this->organizationId,
            $this->projectId,
        );
    }

    /**
     * Return the stable client-facing message shape.
     *
     * @return array{
     *     event_id: string,
     *     event_name: string,
     *     organization_id: int,
     *     project_id: int|null,
     *     occurred_at: string,
     *     correlation_id: string,
     *     execution_id: string|null,
     *     schema_version: int,
     *     data: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_name' => $this->eventName,
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'occurred_at' => $this->occurredAt->toISOString(),
            'correlation_id' => $this->correlationId,
            'execution_id' => $this->executionId,
            'schema_version' => $this->schemaVersion,
            'data' => $this->data,
        ];
    }

    /**
     * Normalize a stable lowercase dotted event name.
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
                'The real-time stream event name must use lowercase dotted notation.',
            );
        }

        return $normalized;
    }

    /**
     * Normalize one required trace identifier.
     */
    private function normalizeRequiredTraceId(
        string $identifier,
        string $name,
    ): string {
        $normalized = trim($identifier);

        if ($normalized === '') {
            throw new InvalidArgumentException(
                "The real-time stream {$name} identifier is required.",
            );
        }

        $this->assertTraceIdIsValid($normalized, $name);

        return $normalized;
    }

    /**
     * Normalize one optional trace identifier.
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
     * Validate a trace identifier against the canonical safe character set.
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
                "The real-time stream {$name} identifier is invalid.",
            );
        }
    }

    /**
     * Ensure the client data remains object-shaped and JSON serializable.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateData(array $data): array
    {
        /** @var array<string, mixed> $validatedData */
        $validatedData = [];

        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    'The real-time stream data must use string keys.',
                );
            }

            $validatedData[$key] = $this->validateValue(
                value: $value,
                path: $key,
            );
        }

        try {
            json_encode(
                $validatedData,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'The real-time stream data must be JSON serializable.',
                previous: $exception,
            );
        }

        return $validatedData;
    }

    /**
     * Reject objects and resources before they can leak through broadcasting.
     */
    private function validateValue(mixed $value, string $path): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                "The real-time stream data value [{$path}] is not JSON-safe.",
            );
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item, int $index): mixed => $this->validateValue(
                    value: $item,
                    path: "{$path}.{$index}",
                ),
                $value,
                array_keys($value),
            );
        }

        /** @var array<string, mixed> $validatedObject */
        $validatedObject = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    "The real-time stream data object [{$path}] must use string keys.",
                );
            }

            $validatedObject[$key] = $this->validateValue(
                value: $item,
                path: "{$path}.{$key}",
            );
        }

        return $validatedObject;
    }
}
