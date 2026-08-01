<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations\Notion;

use App\Application\Integrations\Contracts\IntegrationCircuitBreaker;
use App\Application\Integrations\Contracts\NotionConnectionGateway;
use App\Application\Integrations\Exceptions\IntegrationCircuitOpen;
use App\Application\Integrations\NotionConnectionTestResult;
use App\Domain\Integrations\IntegrationCredentialSecret;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Integrations\NotionConnectionFailureCode;
use App\Domain\Integrations\NotionDatabaseId;

/**
 * Prevents repeated connection tests from contacting an unhealthy provider.
 */
final readonly class CircuitBreakingNotionConnectionGateway implements NotionConnectionGateway
{
    /**
     * Create the Notion connection-test resilience decorator.
     */
    public function __construct(
        private NotionConnectionGateway $inner,
        private IntegrationCircuitBreaker $circuitBreaker,
        private NotionCircuitScope $scope,
    ) {}

    /**
     * Test the connection through a credential-specific circuit.
     */
    public function test(
        IntegrationCredentialSecret $credential,
        NotionDatabaseId $databaseId,
        ?string $expectedWorkspaceId,
        ?string $selectedDataSourceId = null,
    ): NotionConnectionTestResult {
        $provider = IntegrationProvider::Notion->value;

        $scope = $this->scope->forCredential(
            credential: $credential,
            channel: 'connection-test',
        );

        try {
            $this->circuitBreaker->assertCanAttempt(
                provider: $provider,
                scope: $scope,
            );
        } catch (IntegrationCircuitOpen) {
            return NotionConnectionTestResult::failed(
                failureCode: NotionConnectionFailureCode::ProviderUnavailable,
                databaseId: $databaseId->value(),
            );
        }

        $result = $this->inner->test(
            credential: $credential,
            databaseId: $databaseId,
            expectedWorkspaceId: $expectedWorkspaceId,
            selectedDataSourceId: $selectedDataSourceId,
        );

        if ($this->isTransientFailure($result)) {
            $this->circuitBreaker->recordFailure(
                provider: $provider,
                scope: $scope,
            );
        } else {
            $this->circuitBreaker->recordSuccess(
                provider: $provider,
                scope: $scope,
            );
        }

        return $result;
    }

    /**
     * Classify only provider-health failures as circuit failures.
     */
    private function isTransientFailure(
        NotionConnectionTestResult $result,
    ): bool {
        return in_array(
            $result->failureCode,
            [
                NotionConnectionFailureCode::RateLimited,
                NotionConnectionFailureCode::ProviderUnavailable,
                NotionConnectionFailureCode::InvalidProviderResponse,
            ],
            true,
        );
    }
}
