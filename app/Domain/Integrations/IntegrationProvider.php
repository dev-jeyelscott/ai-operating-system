<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

/**
 * Defines external integrations whose credentials may be stored by the system.
 *
 * Integration support is deliberately separate from execution-provider
 * registration. Adding Codex here authorizes configuration and preflight only;
 * it does not enable real repository execution.
 */
enum IntegrationProvider: string
{
    case Notion = 'notion';
    case Codex = 'codex';

    /**
     * Return provider values for route and request constraints.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $provider): string => $provider->value,
            self::cases(),
        );
    }

    /**
     * Return providers that may use the generic direct-write credential route.
     *
     * Codex is deliberately excluded because its credential may only be stored
     * after a successful server-side preflight through the dedicated endpoint.
     *
     * @return list<string>
     */
    public static function directCredentialWriteValues(): array
    {
        return [self::Notion->value];
    }

    /**
     * Return the provider's user-facing name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Notion => 'Notion',
            self::Codex => 'Codex',
        };
    }
}
