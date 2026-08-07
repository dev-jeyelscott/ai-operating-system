<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Application\Projects\Commands\StartProject;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Prepares one StartProject command from the current read-only preflight state.
 */
final readonly class BuildStartProjectCommand
{
    /**
     * Inject the existing preflight query and context fingerprint service.
     */
    public function __construct(
        private GetStartProjectPreflight $preflight,
        private StartProjectContextFingerprint $fingerprints,
    ) {}

    /**
     * Build one immutable command without creating project state.
     */
    public function handle(
        int $organizationId,
        int $projectId,
        int $requestedByUserId,
        string $idempotencyKey,
        ?string $correlationId = null,
        ?string $causationId = null,
    ): StartProject {
        $this->assertPositive(
            $organizationId,
            'organization identifier',
        );

        $this->assertPositive(
            $projectId,
            'project identifier',
        );

        $this->assertPositive(
            $requestedByUserId,
            'requesting user identifier',
        );

        $normalizedIdempotencyKey = trim($idempotencyKey);

        if (
            $normalizedIdempotencyKey === ''
            || mb_strlen($normalizedIdempotencyKey) > 191
        ) {
            throw new InvalidArgumentException(
                'The StartProject idempotency key is required and may not exceed 191 characters.',
            );
        }

        $resolvedCorrelationId = $correlationId
            ?? (string) Str::ulid();

        if (! Str::isUlid($resolvedCorrelationId)) {
            throw new InvalidArgumentException(
                'The StartProject correlation identifier must be a valid ULID.',
            );
        }

        $preflight = $this->preflight->handle(
            organizationId: $organizationId,
            projectId: $projectId,
        );

        return new StartProject(
            organizationId: $organizationId,
            projectId: $projectId,
            requestedByUserId: $requestedByUserId,
            contextFingerprint: $this->fingerprints->fromPreflight($preflight),
            requestIdempotencyKey: $normalizedIdempotencyKey,
            correlationId: $resolvedCorrelationId,
            causationId: $causationId,
        );
    }

    /**
     * Reject non-positive model identifiers before performing queries.
     */
    private function assertPositive(
        int $value,
        string $name,
    ): void {
        if ($value < 1) {
            throw new InvalidArgumentException(
                "The StartProject {$name} must be positive.",
            );
        }
    }
}
