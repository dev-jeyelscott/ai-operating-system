<?php

declare(strict_types=1);

namespace App\Application\Integrations\Data;

use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Integrations\NotionConnectionStatus;

/**
 * Credential-free, immutable Notion configuration snapshot data.
 */
final readonly class NotionProjectConfigurationSnapshot
{
    public const UNCONFIGURED = 'unconfigured';

    public function __construct(
        public string $connectionStatus,
        public ?string $workspaceId,
        public ?string $workspaceName,
        public ?string $databaseId,
        public ?string $databaseName,
        public ?string $dataSourceId,
        public ?string $dataSourceName,
        public ?bool $credentialConfigured,
        public ?int $verifiedCredentialVersion,
        public ?string $verifiedAt,
    ) {}

    /**
     * Create an explicit unconfigured Notion snapshot.
     */
    public static function unconfigured(
        bool $credentialConfigured,
    ): self {
        return new self(
            connectionStatus: self::UNCONFIGURED,
            workspaceId: null,
            workspaceName: null,
            databaseId: null,
            databaseName: null,
            dataSourceId: null,
            dataSourceName: null,
            credentialConfigured: $credentialConfigured,
            verifiedCredentialVersion: null,
            verifiedAt: null,
        );
    }

    /**
     * Create a snapshot for a successfully verified target.
     */
    public static function connected(
        string $workspaceId,
        ?string $workspaceName,
        string $databaseId,
        ?string $databaseName,
        string $dataSourceId,
        ?string $dataSourceName,
        bool $credentialConfigured,
        int $verifiedCredentialVersion,
        string $verifiedAt,
    ): self {
        return new self(
            connectionStatus: NotionConnectionStatus::Connected->value,
            workspaceId: $workspaceId,
            workspaceName: $workspaceName,
            databaseId: $databaseId,
            databaseName: $databaseName,
            dataSourceId: $dataSourceId,
            dataSourceName: $dataSourceName,
            credentialConfigured: $credentialConfigured,
            verifiedCredentialVersion: $verifiedCredentialVersion,
            verifiedAt: $verifiedAt,
        );
    }

    /**
     * Serialize into the canonical snapshot contract.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => IntegrationProvider::Notion->value,

            'connection_status' => $this->connectionStatus,

            'workspace' => [
                'id' => $this->workspaceId,
                'name' => $this->workspaceName,
            ],

            'database' => [
                'id' => $this->databaseId,
                'name' => $this->databaseName,
            ],

            'data_source' => [
                'id' => $this->dataSourceId,
                'name' => $this->dataSourceName,
            ],

            'credential' => [
                'configured' => $this->credentialConfigured,
                'verified_version' => $this->verifiedCredentialVersion,
            ],

            'verified_at' => $this->verifiedAt,
        ];
    }
}
