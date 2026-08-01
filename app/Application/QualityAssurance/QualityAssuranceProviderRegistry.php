<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance;

use App\Application\QualityAssurance\Contracts\QualityAssuranceExecutionProvider;
use App\Application\QualityAssurance\Providers\ValidatingQualityAssuranceExecutionProvider;
use LogicException;

/**
 * Resolves a permitted Layer 3 provider using project fallback order.
 */
final readonly class QualityAssuranceProviderRegistry
{
    /**
     * Register QA providers and the mandatory result validator.
     *
     * @param  iterable<QualityAssuranceExecutionProvider>  $providers
     */
    public function __construct(
        private iterable $providers,
        private QaAssessmentValidator $validator,
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
                return new ValidatingQualityAssuranceExecutionProvider(
                    provider: $provider,
                    validator: $this->validator,
                    capability: $capability,
                );
            }
        }

        throw new LogicException(
            "No permitted provider supports [{$capability}].",
        );
    }
}
