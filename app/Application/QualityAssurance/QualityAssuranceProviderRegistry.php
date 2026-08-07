<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance;

use App\Application\QualityAssurance\Contracts\QualityAssuranceExecutionProvider;
use App\Application\QualityAssurance\Providers\ValidatingQualityAssuranceExecutionProvider;
use App\Domain\Executions\ExecutionCapability;
use LogicException;

/**
 * Resolves a permitted Layer 3 provider using project fallback order.
 */
final readonly class QualityAssuranceProviderRegistry
{
    /**
     * Register QA providers and the result validator.
     *
     * The validator has a default instance to preserve direct registry
     * construction in isolated tests while allowing explicit container
     * injection in the application service provider.
     *
     * @param  iterable<QualityAssuranceExecutionProvider>  $providers
     */
    public function __construct(
        private iterable $providers,
        private QaAssessmentValidator $validator = new QaAssessmentValidator,
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

            $effectiveCapability = ExecutionCapability::fromStored(
                $capability,
            )->value;

            if (
                $provider !== null
                && $provider->supports($effectiveCapability)
            ) {
                return new ValidatingQualityAssuranceExecutionProvider(
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
