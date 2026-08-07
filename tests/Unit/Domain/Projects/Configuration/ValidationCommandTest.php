<?php

declare(strict_types=1);

use App\Domain\Projects\Configuration\Exceptions\InvalidValidationCommand;
use App\Domain\Projects\Configuration\ValidationCommand;

test('a valid project command is normalized', function (): void {
    $command = ValidationCommand::from(
        '  composer test && pnpm test:unit  ',
    );

    expect($command->value())
        ->toBe('composer test && pnpm test:unit')
        ->and((string) $command)
        ->toBe('composer test && pnpm test:unit');
});

test(
    'valid project command syntax is accepted',
    function (string $value): void {
        expect(ValidationCommand::from($value)->value())
            ->toBe(trim($value));
    },
)->with([
    'simple executable' => 'pnpm build',
    'local executable path' => './vendor/bin/phpstan analyse',
    'chained commands' => 'composer test && pnpm test:unit',
    'fallback command' => 'pnpm lint:check || exit 1',
    'pipeline' => 'composer audit | tee audit.log',
    'quoted option' => 'php artisan test --filter="project setup"',
    'environment reference' => 'NODE_ENV=$APP_ENV pnpm build',
]);

test(
    'invalid project commands are rejected',
    function (string $value, string $expectedMessage): void {
        expect(
            fn (): ValidationCommand => ValidationCommand::from($value),
        )->toThrow(
            InvalidValidationCommand::class,
            $expectedMessage,
        );
    },
)->with([
    'empty command' => [
        '',
        'must contain at least one non-whitespace character',
    ],
    'whitespace command' => [
        '   ',
        'must contain at least one non-whitespace character',
    ],
    'line feed' => [
        "composer test\npnpm test",
        'must be a single line',
    ],
    'carriage return' => [
        "composer test\rpnpm test",
        'must be a single line',
    ],
    'null byte' => [
        "composer\0 test",
        'contains an unsupported control character',
    ],
    'control character' => [
        "composer\x07 test",
        'contains an unsupported control character',
    ],
    'oversized command' => [
        str_repeat('a', ValidationCommand::MAX_LENGTH + 1),
        'must not exceed',
    ],
]);

test(
    'commands containing null bytes are rejected',
    function (string $command): void {
        expect(
            fn (): ValidationCommand => ValidationCommand::from($command),
        )->toThrow(InvalidValidationCommand::class);
    },
)->with([
    'leading null byte' => "\0composer types:check",
    'embedded null byte' => "composer\0 types:check",
    'trailing null byte' => "composer types:check\0",
]);
