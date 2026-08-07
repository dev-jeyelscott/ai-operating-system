<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Models\OfficeProjection;

/**
 * Returns the persisted office projection with lazy read repair on a cache miss.
 */
final readonly class GetProjectOfficeProjection
{
    /**
     * Inject the authoritative projection builder.
     */
    public function __construct(
        private BuildOfficeProjection $builder,
    ) {}

    /**
     * Return one tenant-scoped office projection contract.
     *
     * @return array<string, mixed>
     */
    public function handle(
        int $organizationId,
        int $projectId,
    ): array {
        $projection = OfficeProjection::query()
            ->forOrganization($organizationId)
            ->forProject($projectId)
            ->first();

        if (! $projection instanceof OfficeProjection) {
            $projection = $this->builder->handle(
                organizationId: $organizationId,
                projectId: $projectId,
            );
        }

        return [
            'metadata' => [
                'schemaVersion' => $projection->schema_version,
                'fingerprint' => $projection->fingerprint,
                'lastEventSequence' => $projection->last_event_sequence,
                'lastEventId' => $projection->last_event_id,
                'projectedAt' => $projection
                    ->projected_at
                    ->toIso8601String(),
                'rebuiltAt' => $projection
                    ->rebuilt_at
                    ?->toIso8601String(),
            ],
            ...$projection->state,
        ];
    }
}
