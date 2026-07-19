<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
use LogicException;

final class TrustProxies extends FrameworkTrustProxies
{
    /**
     * Resolve the explicitly configured trusted proxy boundary.
     *
     * An empty array means no proxy is trusted by default. Static overrides
     * remain supported for focused tests through TrustProxies::at().
     *
     * @return array<int, string>|string|null
     */
    protected function proxies(): array|string|null
    {
        if (self::$alwaysTrustProxies !== null) {
            return self::$alwaysTrustProxies;
        }

        $configuredProxies = config('http.trusted_proxies', []);

        if (
            ! is_array($configuredProxies)
            && ! is_string($configuredProxies)
            && $configuredProxies !== null
        ) {
            throw new LogicException(
                'http.trusted_proxies must be an array, wildcard string, or null.',
            );
        }

        return $configuredProxies;
    }

    /**
     * Resolve the forwarded-header convention trusted by the application.
     *
     * Static overrides remain supported for focused tests through
     * TrustProxies::withHeaders().
     */
    protected function headers(): int
    {
        if (self::$alwaysTrustHeaders !== null) {
            return self::$alwaysTrustHeaders;
        }

        $configuredHeaders = config('http.trusted_proxy_headers');

        if (! is_int($configuredHeaders)) {
            throw new LogicException(
                'http.trusted_proxy_headers must be an integer header mask.',
            );
        }

        return $configuredHeaders;
    }
}
