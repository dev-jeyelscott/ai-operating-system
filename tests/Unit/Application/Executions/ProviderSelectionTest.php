<?php

declare(strict_types=1);

use App\Application\Executions\Contracts\DescribesExecutionProvider;
use App\Application\Executions\Data\ExecutionProviderMetadata;
use App\Application\Executions\Data\ProviderSelection;
use App\Domain\Executions\ExecutionCapability;
use LogicException;

it('creates real-shaped metadata without simulation values', function (): void {
    $provider = new class implements DescribesExecutionProvider
    {
        public function id(): string
        {
            return 'codex';
        }

        public function supports(string $capability): bool
        {
            return $capability
                === ExecutionCapability::DevelopmentExecute->value;
        }

        public function metadata(): ExecutionProviderMetadata
        {
            return new ExecutionProviderMetadata(
                modelIdentifier: 'codex-model',
                protocolVersion: 'codex-app-server.v1',
                sandboxProfile: 'workspace-write',
                simulation: false,
            );
        }
    };

    $selection = ProviderSelection::fromProvider(
        requestedCapability: 'development.simulation',
        provider: $provider,
        selectionSource: 'immutable_configuration_snapshot',
    );

    expect($selection->providerId)->toBe('codex')
        ->and($selection->modelIdentifier)->toBe('codex-model')
        ->and($selection->effectiveCapability)
        ->toBe(ExecutionCapability::DevelopmentExecute)
        ->and($selection->simulation)->toBeFalse();
});

it('fails closed when a provider does not support the capability', function (): void {
    $provider = new class implements DescribesExecutionProvider
    {
        public function id(): string
        {
            return 'unsupported';
        }

        public function supports(string $capability): bool
        {
            return false;
        }

        public function metadata(): ExecutionProviderMetadata
        {
            return new ExecutionProviderMetadata(
                modelIdentifier: null,
                protocolVersion: 'unsupported.v1',
                sandboxProfile: 'none',
                simulation: false,
            );
        }
    };

    ProviderSelection::fromProvider(
        requestedCapability: 'planning.generate',
        provider: $provider,
        selectionSource: 'immutable_configuration_snapshot',
    );
})->throws(
    LogicException::class,
    'does not support capability',
);
