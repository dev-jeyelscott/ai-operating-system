<?php

declare(strict_types=1);

namespace App\Application\Projects\Commands;

use App\Application\Shared\Idempotency\Contracts\IdempotentCommand;

/**
 * Requests one project planning workflow for one immutable context version.
 */
final readonly class StartProject implements IdempotentCommand
{
    /**
     * Store the immutable StartProject request.
     */
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $requestedByUserId,
        public string $contextFingerprint,
        public string $requestIdempotencyKey,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}

    /**
     * Return the caller-supplied key used for durable result replay.
     */
    public function idempotencyKey(): string
    {
        return $this->requestIdempotencyKey;
    }

    /**
     * Prevent the same key from colliding across tenants or projects.
     */
    public function idempotencyScope(): string
    {
        return sprintf(
            'organization:%d:project:%d:start_project',
            $this->organizationId,
            $this->projectId,
        );
    }

    /**
     * Fingerprint only stable, non-secret command inputs.
     *
     * Correlation and causation identifiers are intentionally excluded because
     * replay must return the original result even when transport metadata differs.
     *
     * @return array<string, mixed>
     */
    public function idempotencyPayload(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'requested_by_user_id' => $this->requestedByUserId,
            'context_fingerprint' => $this->contextFingerprint,
        ];
    }

    /**
     * Return a persistence-safe hash of the raw idempotency key.
     */
    public function persistedIdempotencyHash(): string
    {
        return hash(
            'sha256',
            $this->requestIdempotencyKey,
        );
    }
}
