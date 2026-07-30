<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance;

use App\Application\QualityAssurance\Contracts\QualityAssuranceExecutionProvider;
use LogicException;

/**
 * Resolves a permitted Layer 3 provider using project fallback order.
 */
final readonly class QualityAssuranceProviderRegistry
{
    /**
     * @param  iterable<QualityAssuranceExecutionProvider>  $providers
     */
    public function __construct(
        private iterable $providers,
    ) {}

    /**
     * Resolve the first permitted provider supporting the capability.
     *
     * @param  list<string>  $fallbackOrder
     */
    public function resolve(
        array $fallbackOrder,
        string $capability,
    ): QualityAssuranceExecutionProvider {
        $providersById = [];

        foreach ($this->providers as $provider) {
            $providersById[$provider->id()] = $provider;
        }

        foreach ($fallbackOrder as $providerId) {
            $provider = $providersById[$providerId] ?? null;

            if (
                $provider !== null
                && $provider->supports($capability)
            ) {
                return $provider;
            }
        }

        throw new LogicException(
            "No permitted provider supports [{$capability}].",
        );
    }
}
