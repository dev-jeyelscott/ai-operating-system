<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations\Notion;

use App\Application\Integrations\Contracts\IntegrationCircuitBreaker;
use App\Application\Integrations\Contracts\NotionPublicationClient;
use App\Application\Integrations\Data\NotionDataSource;
use App\Application\Integrations\Data\NotionPage;
use App\Application\Integrations\Exceptions\IntegrationCircuitOpen;
use App\Application\Integrations\NotionPublicationException;
use App\Domain\Integrations\IntegrationCredentialSecret;
use App\Domain\Integrations\IntegrationProvider;
use Closure;

/**
 * Applies circuit-breaker policy around the existing Notion HTTP adapter.
 *
 * The inner adapter retains ownership of HTTP retries, timeouts, response
 * validation, request IDs, and provider error normalization.
 */
final readonly class CircuitBreakingNotionPublicationClient implements NotionPublicationClient
{
    /**
     * Create the Notion resilience decorator.
     */
    public function __construct(
        private NotionPublicationClient $inner,
        private IntegrationCircuitBreaker $circuitBreaker,
        private NotionCircuitScope $scope,
    ) {}

    /**
     * Retrieve and validate a Notion data source.
     */
    public function retrieveDataSource(
        IntegrationCredentialSecret $credential,
        string $dataSourceId,
    ): NotionDataSource {
        return $this->execute(
            credential: $credential,
            channel: 'publication-read',
            operation: fn (): NotionDataSource => $this->inner->retrieveDataSource(
                credential: $credential,
                dataSourceId: $dataSourceId,
            ),
        );
    }

    /**
     * Find existing pages by the stable external ticket key.
     *
     * @return list<NotionPage>
     */
    public function findPagesByTicketKey(
        IntegrationCredentialSecret $credential,
        string $dataSourceId,
        string $ticketKey,
    ): array {
        return $this->execute(
            credential: $credential,
            channel: 'publication-read',
            operation: fn (): array => $this->inner->findPagesByTicketKey(
                credential: $credential,
                dataSourceId: $dataSourceId,
                ticketKey: $ticketKey,
            ),
        );
    }

    /**
     * Create one mapped Notion page.
     *
     * @param  array<string, mixed>  $properties
     */
    public function createPage(
        IntegrationCredentialSecret $credential,
        string $dataSourceId,
        array $properties,
        string $body,
    ): NotionPage {
        return $this->execute(
            credential: $credential,
            channel: 'publication-write',
            operation: fn (): NotionPage => $this->inner->createPage(
                credential: $credential,
                dataSourceId: $dataSourceId,
                properties: $properties,
                body: $body,
            ),
        );
    }

    /**
     * Update one previously mapped Notion page.
     *
     * @param  array<string, mixed>  $properties
     */
    public function updatePage(
        IntegrationCredentialSecret $credential,
        string $pageId,
        array $properties,
        string $body,
    ): NotionPage {
        return $this->execute(
            credential: $credential,
            channel: 'publication-write',
            operation: fn (): NotionPage => $this->inner->updatePage(
                credential: $credential,
                pageId: $pageId,
                properties: $properties,
                body: $body,
            ),
        );
    }

    /**
     * Retrieve one existing Notion page.
     */
    public function retrievePage(
        IntegrationCredentialSecret $credential,
        string $pageId,
    ): NotionPage {
        return $this->execute(
            credential: $credential,
            channel: 'publication-read',
            operation: fn (): NotionPage => $this->inner->retrievePage(
                credential: $credential,
                pageId: $pageId,
            ),
        );
    }

    /**
     * Retrieve the normalized managed page body.
     */
    public function retrievePageBody(
        IntegrationCredentialSecret $credential,
        string $pageId,
    ): string {
        return $this->execute(
            credential: $credential,
            channel: 'publication-read',
            operation: fn (): string => $this->inner->retrievePageBody(
                credential: $credential,
                pageId: $pageId,
            ),
        );
    }

    /**
     * Execute one provider operation through the appropriate circuit.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    private function execute(
        IntegrationCredentialSecret $credential,
        string $channel,
        Closure $operation,
    ): mixed {
        $provider = IntegrationProvider::Notion->value;

        $scope = $this->scope->forCredential(
            credential: $credential,
            channel: $channel,
        );

        try {
            $this->circuitBreaker->assertCanAttempt(
                provider: $provider,
                scope: $scope,
            );
        } catch (IntegrationCircuitOpen) {
            throw new NotionPublicationException(
                category: 'circuit_open',
                retryable: true,
            );
        }

        try {
            $result = $operation();

            $this->circuitBreaker->recordSuccess(
                provider: $provider,
                scope: $scope,
            );

            return $result;
        } catch (NotionPublicationException $exception) {
            if ($this->isTransientFailure($exception->category)) {
                $this->circuitBreaker->recordFailure(
                    provider: $provider,
                    scope: $scope,
                );
            } else {
                /*
                 * Authentication, permission, schema, and data conflicts prove
                 * that Notion responded. They require remediation rather than
                 * temporary outage protection.
                 */
                $this->circuitBreaker->recordSuccess(
                    provider: $provider,
                    scope: $scope,
                );
            }

            throw $exception;
        }
    }

    /**
     * Determine whether one normalized provider category affects health.
     */
    private function isTransientFailure(string $category): bool
    {
        $categories = config(
            'integration-resilience.notion.transient_failure_categories',
            [],
        );

        if (! is_array($categories)) {
            return false;
        }

        foreach ($categories as $candidate) {
            if (
                is_string($candidate)
                && $candidate === $category
            ) {
                return true;
            }
        }

        return false;
    }
}
