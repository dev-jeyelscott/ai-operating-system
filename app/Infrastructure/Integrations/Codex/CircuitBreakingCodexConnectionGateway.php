<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations\Codex;

use App\Application\Integrations\CodexConnectionTestResult;
use App\Application\Integrations\Contracts\CodexConnectionGateway;
use App\Application\Integrations\Contracts\IntegrationCircuitBreaker;
use App\Application\Integrations\Exceptions\IntegrationCircuitOpen;
use App\Domain\Integrations\CodexConnectionFailureCode;
use App\Domain\Integrations\IntegrationCredentialSecret;
use App\Domain\Integrations\IntegrationProvider;

/**
 * Prevents repeated Codex preflight calls from hammering an unhealthy provider.
 */
final readonly class CircuitBreakingCodexConnectionGateway implements CodexConnectionGateway
{
    /**
     * Create the Codex preflight resilience decorator.
     */
    public function __construct(
        private CodexConnectionGateway $inner,
        private IntegrationCircuitBreaker $circuitBreaker,
        private CodexCircuitScope $scope,
    ) {}

    /**
     * Run preflight through a credential-specific circuit.
     */
    public function test(
        IntegrationCredentialSecret $credential,
        string $modelIdentifier,
    ): CodexConnectionTestResult {
        $provider = IntegrationProvider::Codex->value;
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
            return CodexConnectionTestResult::failed(
                failureCode: CodexConnectionFailureCode::ProviderUnavailable,
                modelIdentifier: $modelIdentifier,
            );
        }

        $result = $this->inner->test(
            credential: $credential,
            modelIdentifier: $modelIdentifier,
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
    private function isTransientFailure(CodexConnectionTestResult $result): bool
    {
        return in_array(
            $result->failureCode,
            [
                CodexConnectionFailureCode::RateLimited,
                CodexConnectionFailureCode::ProviderUnavailable,
                CodexConnectionFailureCode::InvalidProviderResponse,
            ],
            true,
        );
    }
}
