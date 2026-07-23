<?php

declare(strict_types=1);

namespace App\Application\Projects\Data;

use App\Application\Integrations\Data\ProjectIntegrationsSnapshot;

/**
 * Complete canonical and credential-free project configuration snapshot.
 */
final readonly class ProjectConfigurationSnapshot
{
    /**
     * @param  array<string, mixed>  $configuration
     */
    public function __construct(
        private array $configuration,
        private ProjectIntegrationsSnapshot $integrations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->configuration,
            'integrations' => $this->integrations->toArray(),
        ];
    }
}
