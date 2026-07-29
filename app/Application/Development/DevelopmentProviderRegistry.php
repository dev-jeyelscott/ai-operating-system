<?php

declare(strict_types=1);

namespace App\Application\Development;

use App\Application\Development\Contracts\DevelopmentExecutionProvider;

final readonly class DevelopmentProviderRegistry
{
    /** @param iterable<DevelopmentExecutionProvider> $providers */
    public function __construct(private iterable $providers) {}

    /** @param list<string> $fallbackOrder */
    public function resolve(array $fallbackOrder, string $capability): DevelopmentExecutionProvider
    {
        $providers = [];
        foreach ($this->providers as $provider) {
            $providers[$provider->id()] = $provider;
        }

        foreach ($fallbackOrder as $providerId) {
            $provider = $providers[$providerId] ?? null;
            if ($provider !== null && $provider->supports($capability)) {
                return $provider;
            }
        }

        throw new \LogicException("No permitted provider supports [{$capability}].");
    }
}
