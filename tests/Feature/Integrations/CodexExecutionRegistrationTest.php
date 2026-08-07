<?php

declare(strict_types=1);

use App\Application\Development\DevelopmentProviderRegistry;

it('does not register codex as a real development execution provider', function (): void {
    $registry = app(DevelopmentProviderRegistry::class);

    expect(fn () => $registry->resolve(
        fallbackOrder: ['codex'],
        capability: 'development.execute',
    ))->toThrow(
        LogicException::class,
        'No permitted provider supports [development.execute].',
    );
});
