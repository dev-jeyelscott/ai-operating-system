<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Application\Planning\Contracts\ExecutionProvider;
use App\Domain\Projects\Configuration\ProviderPolicy;
use LogicException;

/** Resolves an allowed provider in the project's explicit fallback order. */
final readonly class ExecutionProviderRegistry
{
    /** @param iterable<ExecutionProvider> $providers */
    public function __construct(private iterable $providers) {}

    public function resolve(ProviderPolicy $policy, string $capability): ExecutionProvider
    {
        $providers = [];

        foreach ($this->providers as $provider) {
            $providers[$provider->id()] = $provider;
        }

        foreach ($policy->fallbackOrder as $providerId) {
            $provider = $providers[$providerId] ?? null;

            if ($provider !== null && $provider->supports($capability)) {
                return $provider;
            }
        }

        throw new LogicException(sprintf(
            'No allowed provider supports capability [%s].',
            $capability,
        ));
    }
}
