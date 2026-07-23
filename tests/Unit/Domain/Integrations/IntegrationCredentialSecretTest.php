<?php

declare(strict_types=1);

use App\Domain\Integrations\Exceptions\InvalidIntegrationCredential;
use App\Domain\Integrations\IntegrationCredentialSecret;

test('a valid integration credential is retained exactly', function (): void {
    $plaintext = 'secret_notion_abcdefghijklmnopqrstuvwxyz';

    $credential = IntegrationCredentialSecret::from($plaintext);

    expect($credential->reveal())
        ->toBe($plaintext);
});

test('equal credentials use constant time comparison', function (): void {
    $first = IntegrationCredentialSecret::from(
        'secret_notion_abcdefghijklmnopqrstuvwxyz',
    );

    $equivalent = IntegrationCredentialSecret::from(
        'secret_notion_abcdefghijklmnopqrstuvwxyz',
    );

    $different = IntegrationCredentialSecret::from(
        'secret_notion_zyxwvutsrqponmlkjihgfedcba',
    );

    expect($first->equals($equivalent))
        ->toBeTrue()
        ->and($first->equals($different))
        ->toBeFalse();
});

test(
    'invalid integration credentials are rejected',
    function (string $credential): void {
        IntegrationCredentialSecret::from($credential);
    },
)->with([
    'too short' => ['short'],
    'leading whitespace' => [
        ' secret_notion_abcdefghijklmnopqrstuvwxyz',
    ],
    'trailing whitespace' => [
        'secret_notion_abcdefghijklmnopqrstuvwxyz ',
    ],
    'line feed' => [
        "secret_notion_abcdefghij\nklmnopqrstuvwxyz",
    ],
    'carriage return' => [
        "secret_notion_abcdefghij\rklmnopqrstuvwxyz",
    ],
    'null byte' => [
        "secret_notion_abcdefghij\0klmnopqrstuvwxyz",
    ],
])->throws(InvalidIntegrationCredential::class);

test('debug output redacts the credential', function (): void {
    $plaintext = 'secret_notion_abcdefghijklmnopqrstuvwxyz';

    $credential = IntegrationCredentialSecret::from($plaintext);

    /*
     * __debugInfo() is PHP's supported customization point for var_dump().
     * Capture the dump output so the test can verify that plaintext is absent.
     */
    ob_start();

    var_dump($credential);

    $debugOutput = ob_get_clean();

    if (! is_string($debugOutput)) {
        throw new RuntimeException(
            'Unable to capture the integration credential debug output.',
        );
    }

    expect($debugOutput)
        ->not->toContain($plaintext)
        ->toContain('[REDACTED]');
});
