<?php

declare(strict_types=1);

namespace App\Application\Development;

use App\Application\Development\Contracts\DevelopmentExecutionProvider;
use App\Application\Development\Providers\ValidatingDevelopmentExecutionProvider;
use App\Domain\Executions\ExecutionCapability;
use LogicException;

/**
 * Resolves permitted Layer 2 providers through the configured fallback order.
 */
final readonly class DevelopmentProviderRegistry
{
    /**
     * Register development providers and the result validator.
     *
     * The validator has a default instance to preserve direct registry
     * construction in isolated tests while allowing explicit container
     * injection in the application service provider.
     *
     * @param  iterable<DevelopmentExecutionProvider>  $providers
     */
    public function __construct(
        private iterable $providers,
        private DevelopmentResultValidator $validator = new DevelopmentResultValidator,
    ) {}

    /**
     * Resolve the first permitted provider supporting the capability.
     *
     * @param  list<string>  $fallbackOrder
     */
    public function resolve(
        array $fallbackOrder,
        string $capability,
    ): DevelopmentExecutionProvider {
        $providers = [];

        foreach ($this->providers as $provider) {
            $providers[$provider->id()] = $provider;
        }

        foreach ($fallbackOrder as $providerId) {
            $provider = $providers[$providerId] ?? null;

            $effectiveCapability = ExecutionCapability::fromStored(
                $capability,
            )->value;

            if (
                $provider !== null
                && $provider->supports($effectiveCapability)
            ) {
                return new ValidatingDevelopmentExecutionProvider(
                    provider: $provider,
                    validator: $this->validator,
                    capability: $effectiveCapability,
                );
            }
        }

        throw new LogicException(
            "No permitted provider supports [{$capability}].",
        );
    }
}
