<?php

declare(strict_types=1);

use App\Support\Http\TrustedProxyConfiguration;
use Illuminate\Http\Request;

test('empty proxy configuration trusts no explicit proxy', function (): void {
    expect(TrustedProxyConfiguration::proxies(null))
        ->toBe([])
        ->and(TrustedProxyConfiguration::proxies(''))
        ->toBe([])
        ->and(TrustedProxyConfiguration::proxies('   '))
        ->toBe([]);
});

test('wildcard proxy configuration is preserved', function (): void {
    expect(TrustedProxyConfiguration::proxies('*'))
        ->toBe('*');
});

test('proxy addresses and cidrs are normalized', function (): void {
    expect(
        TrustedProxyConfiguration::proxies(
            ' 10.0.0.10, 10.0.0.0/8, 2001:db8::/32, 10.0.0.10 ',
        ),
    )->toBe([
        '10.0.0.10',
        '10.0.0.0/8',
        '2001:db8::/32',
    ]);
});

test(
    'invalid proxy entries are rejected',
    function (string $value): void {
        expect(
            fn (): array|string => TrustedProxyConfiguration::proxies(
                $value,
            ),
        )->toThrow(InvalidArgumentException::class);
    },
)->with([
    'hostname' => 'proxy.internal',
    'invalid ipv4 address' => '999.0.0.1',
    'invalid ipv4 cidr' => '10.0.0.0/33',
    'invalid ipv6 cidr' => '2001:db8::/129',
]);

test('non-string proxy configuration is rejected', function (): void {
    expect(
        fn (): array|string => TrustedProxyConfiguration::proxies([
            '10.0.0.1',
        ]),
    )->toThrow(
        InvalidArgumentException::class,
        'TRUSTED_PROXIES must be a string.',
    );
});

test(
    'header modes resolve to the expected header mask',
    function (string $mode, int $expectedHeaders): void {
        expect(TrustedProxyConfiguration::headers($mode))
            ->toBe($expectedHeaders);
    },
)->with([
    'standard forwarded headers' => [
        TrustedProxyConfiguration::MODE_X_FORWARDED,
        Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO,
    ],
    'rfc forwarded header' => [
        TrustedProxyConfiguration::MODE_FORWARDED,
        Request::HEADER_FORWARDED,
    ],
    'aws elb headers' => [
        TrustedProxyConfiguration::MODE_AWS_ELB,
        Request::HEADER_X_FORWARDED_AWS_ELB,
    ],
]);

test('empty header mode defaults to x forwarded headers', function (): void {
    expect(TrustedProxyConfiguration::headers(null))
        ->toBe(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO,
        );
});

test('unsupported header modes are rejected', function (): void {
    expect(
        fn (): int => TrustedProxyConfiguration::headers('all_headers'),
    )->toThrow(
        InvalidArgumentException::class,
        'TRUSTED_PROXY_HEADERS must be x_forwarded, forwarded, or aws_elb.',
    );
});
