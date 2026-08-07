<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Application\Planning\Contracts\ExecutionProvider;
use App\Application\Planning\Providers\ValidatingPlanningExecutionProvider;
use App\Domain\Executions\ExecutionCapability;
use App\Domain\Projects\Configuration\ProviderPolicy;
use LogicException;

/**
 * Resolves an allowed planning provider in the project's fallback order.
 */
final readonly class ExecutionProviderRegistry
{
    /**
     * Register planning providers and the result validator.
     *
     * The validator has a default instance to preserve direct registry
     * construction in isolated tests while allowing explicit container
     * injection in the application service provider.
     *
     * @param  iterable<ExecutionProvider>  $providers
     */
    public function __construct(
        private iterable $providers,
        private PlanningResultValidator $validator = new PlanningResultValidator,
    ) {}

    /**
     * Resolve the first allowed provider and enforce result validation.
     */
    public function resolve(
        ProviderPolicy $policy,
        string $capability,
    ): ExecutionProvider {
        $providers = [];

        foreach ($this->providers as $provider) {
            $providers[$provider->id()] = $provider;
        }

        foreach ($policy->fallbackOrder as $providerId) {
            $provider = $providers[$providerId] ?? null;
            $effectiveCapability = ExecutionCapability::fromStored(
                $capability,
            )->value;

            if (
                $provider !== null
                && $provider->supports($effectiveCapability)
            ) {
                return new ValidatingPlanningExecutionProvider(
                    provider: $provider,
                    validator: $this->validator,
                    capability: $effectiveCapability,
                );
            }
        }

        throw new LogicException(sprintf(
            'No allowed provider supports capability [%s].',
            $capability,
        ));
    }
}
