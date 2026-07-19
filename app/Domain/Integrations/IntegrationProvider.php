<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

/**
 * Defines external integrations whose credentials may be stored by the system.
 *
 * Only Notion is enabled for the approved MVP scope. Additional providers must
 * be added deliberately together with authorization, validation, and adapter
 * support.
 */
enum IntegrationProvider: string
{
    case Notion = 'notion';

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
     * Return the provider's user-facing name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Notion => 'Notion',
        };
    }
}
