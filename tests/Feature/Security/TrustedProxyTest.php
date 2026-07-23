<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    /*
     * Laravel stores trusted proxy configuration in static middleware state.
     * Clear it before every test so configuration cannot leak between cases.
     */
    TrustProxies::flushState();

    /*
     * Expose only the normalized request context needed by these tests.
     * Do not return raw forwarded-header chains.
     */
    Route::get(
        '/_test/trusted-proxy-context',
        static fn (Request $request): array => [
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'scheme' => $request->getScheme(),
            'host' => $request->getHost(),
        ],
    );
});

afterEach(function (): void {
    /*
     * Restore Laravel's static trusted-proxy state after each test.
     */
    TrustProxies::flushState();
});

test(
    'an approved proxy address or cidr may provide client context',
    function (string $trustedProxy): void {
        TrustProxies::at([$trustedProxy]);
        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO,
        );

        $this
            ->withServerVariables([
                'REMOTE_ADDR' => '10.20.30.40',
                'SERVER_PORT' => 80,
                'HTTPS' => 'off',
            ])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.20',
                'X-Forwarded-Host' => 'app.example.test',
                'X-Forwarded-Port' => '443',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('/_test/trusted-proxy-context')
            ->assertOk()
            ->assertJsonPath('ip', '203.0.113.20')
            ->assertJsonPath('secure', true)
            ->assertJsonPath('scheme', 'https')
            ->assertJsonPath('host', 'app.example.test');
    },
)->with([
    'exact proxy address' => '10.20.30.40',
    'proxy cidr range' => '10.0.0.0/8',
]);

test('an untrusted client cannot spoof its address or scheme', function (): void {
    TrustProxies::at(['10.0.0.0/8']);
    TrustProxies::withHeaders(
        Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO,
    );

    $this
        ->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.50',
            'SERVER_PORT' => 80,
            'HTTPS' => 'off',
        ])
        ->withHeaders([
            'X-Forwarded-For' => '203.0.113.99',
            'X-Forwarded-Host' => 'attacker.example',
            'X-Forwarded-Port' => '443',
            'X-Forwarded-Proto' => 'https',
        ])
        ->get('/_test/trusted-proxy-context')
        ->assertOk()
        ->assertJsonPath('ip', '198.51.100.50')
        ->assertJsonPath('secure', false)
        ->assertJsonPath('scheme', 'http');
});

test('rfc forwarded mode resolves approved proxy context', function (): void {
    TrustProxies::at(['10.0.0.0/8']);
    TrustProxies::withHeaders(Request::HEADER_FORWARDED);

    $this
        ->withServerVariables([
            'REMOTE_ADDR' => '10.20.30.40',
            'SERVER_PORT' => 80,
            'HTTPS' => 'off',
        ])
        ->withHeader(
            'Forwarded',
            'for=203.0.113.30;proto=https;host=app.example.test',
        )
        ->get('/_test/trusted-proxy-context')
        ->assertOk()
        ->assertJsonPath('ip', '203.0.113.30')
        ->assertJsonPath('secure', true)
        ->assertJsonPath('scheme', 'https')
        ->assertJsonPath('host', 'app.example.test');
});

test('aws elb mode resolves approved proxy context', function (): void {
    TrustProxies::at('*');
    TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_AWS_ELB);

    $this
        ->withServerVariables([
            'REMOTE_ADDR' => '10.20.30.40',
            'SERVER_PORT' => 80,
            'HTTPS' => 'off',
        ])
        ->withHeaders([
            'X-Forwarded-For' => '203.0.113.40',
            'X-Forwarded-Port' => '443',
            'X-Forwarded-Proto' => 'https',
        ])
        ->get('/_test/trusted-proxy-context')
        ->assertOk()
        ->assertJsonPath('ip', '203.0.113.40')
        ->assertJsonPath('secure', true)
        ->assertJsonPath('scheme', 'https');
});

test('application proxy middleware reads trusted proxy configuration', function (): void {
    config()->set(
        'http.trusted_proxies',
        ['10.0.0.0/8'],
    );

    config()->set(
        'http.trusted_proxy_headers',
        Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PROTO,
    );

    $this
        ->withServerVariables([
            'REMOTE_ADDR' => '10.20.30.40',
            'SERVER_PORT' => 80,
            'HTTPS' => 'off',
        ])
        ->withHeaders([
            'X-Forwarded-For' => '203.0.113.20',
            'X-Forwarded-Proto' => 'https',
        ])
        ->get('/_test/trusted-proxy-context')
        ->assertOk()
        ->assertJsonPath('ip', '203.0.113.20')
        ->assertJsonPath('secure', true)
        ->assertJsonPath('scheme', 'https');
});
