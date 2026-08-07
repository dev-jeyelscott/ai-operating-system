<?php

declare(strict_types=1);

namespace App\Application\Integrations\Data;

/**
 * Credential-free integration snapshot for one project.
 */
final readonly class ProjectIntegrationsSnapshot
{
    public function __construct(
        public NotionProjectConfigurationSnapshot $notion,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'notion' => $this->notion->toArray(),
        ];
    }
}
