<?php

declare(strict_types=1);

use App\Support\Http\TrustedProxyConfiguration;

return [
    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Keep this empty when requests reach Laravel directly. Prefer explicit
    | proxy IP addresses or CIDR ranges. Wildcard trust is allowed only when
    | network controls prevent direct access to the application.
    |
    */

    'trusted_proxies' => TrustedProxyConfiguration::proxies(
        env('TRUSTED_PROXIES'),
    ),

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxy Headers
    |--------------------------------------------------------------------------
    |
    | Supported modes:
    |
    | - x_forwarded: conventional X-Forwarded-* headers;
    | - forwarded: RFC Forwarded header;
    | - aws_elb: AWS Elastic Load Balancer conventions.
    |
    */

    'trusted_proxy_headers' => TrustedProxyConfiguration::headers(
        env(
            'TRUSTED_PROXY_HEADERS',
            TrustedProxyConfiguration::MODE_X_FORWARDED,
        ),
    ),
];
