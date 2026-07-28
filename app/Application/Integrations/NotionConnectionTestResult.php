<?php

declare(strict_types=1);

namespace App\Application\Integrations;

use App\Domain\Integrations\NotionConnectionFailureCode;

/**
 * Contains a sanitized Notion connection-test result.
 */
final readonly class NotionConnectionTestResult
{
    /**
     * Create an immutable connection-test result.
     */
    private function __construct(
        public bool $successful,
        public ?string $workspaceId,
        public ?string $workspaceName,
        public string $databaseId,
        public ?string $databaseName,
        public ?string $dataSourceId,
        public ?string $dataSourceName,
        public ?NotionConnectionFailureCode $failureCode,
        public ?string $providerRequestId,
        public bool $configurationChanged,
        /** @var list<array{id:string,name:string|null}> */
        public array $dataSourceCandidates = [],
    ) {}

    /**
     * Create a successful connection result.
     */
    public static function connected(
        string $workspaceId,
        ?string $workspaceName,
        string $databaseId,
        ?string $databaseName,
        string $dataSourceId,
        ?string $dataSourceName,
        ?string $providerRequestId,
    ): self {
        return new self(
            successful: true,
            workspaceId: $workspaceId,
            workspaceName: $workspaceName,
            databaseId: $databaseId,
            databaseName: $databaseName,
            dataSourceId: $dataSourceId,
            dataSourceName: $dataSourceName,
            failureCode: null,
            providerRequestId: $providerRequestId,
            configurationChanged: false,
        );
    }

    /**
     * Create a sanitized failed connection result.
     */
    public static function failed(
        NotionConnectionFailureCode $failureCode,
        string $databaseId,
        ?string $providerRequestId = null,
        ?string $workspaceId = null,
        ?string $workspaceName = null,
    ): self {
        return new self(
            successful: false,
            workspaceId: $workspaceId,
            workspaceName: $workspaceName,
            databaseId: $databaseId,
            databaseName: null,
            dataSourceId: null,
            dataSourceName: null,
            failureCode: $failureCode,
            providerRequestId: $providerRequestId,
            configurationChanged: false,
        );
    }

    /** @param list<array{id:string,name:string|null}> $candidates */
    public static function dataSourceSelectionRequired(string $databaseId, array $candidates, ?string $providerRequestId, ?string $workspaceId, ?string $workspaceName): self
    {
        return new self(false, $workspaceId, $workspaceName, $databaseId, null, null, null, null, $providerRequestId, false, $candidates);
    }

    /**
     * Attach whether the persisted project configuration materially changed.
     */
    public function withConfigurationChanged(
        bool $configurationChanged,
    ): self {
        return new self(
            successful: $this->successful,
            workspaceId: $this->workspaceId,
            workspaceName: $this->workspaceName,
            databaseId: $this->databaseId,
            databaseName: $this->databaseName,
            dataSourceId: $this->dataSourceId,
            dataSourceName: $this->dataSourceName,
            failureCode: $this->failureCode,
            providerRequestId: $this->providerRequestId,
            configurationChanged: $configurationChanged,
            dataSourceCandidates: $this->dataSourceCandidates,
        );
    }

    /**
     * Return the safe remediation message for a failure.
     */
    public function userMessage(): string
    {
        if ($this->dataSourceCandidates !== []) {
            return 'Choose the Notion data source to use for ticket publication.';
        }

        return $this->failureCode?->userMessage()
            ?? 'The Notion connection was validated successfully.';
    }
}
