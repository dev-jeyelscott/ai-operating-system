<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Request;
use InvalidArgumentException;

final class TrustedProxyConfiguration
{
    public const MODE_X_FORWARDED = 'x_forwarded';

    public const MODE_FORWARDED = 'forwarded';

    public const MODE_AWS_ELB = 'aws_elb';

    /**
     * Headers used by conventional reverse proxies such as Nginx and Traefik.
     *
     * X-Forwarded-Host is included because Laravel may use it when generating
     * absolute URLs. The upstream proxy must overwrite or remove any
     * client-supplied value before forwarding the request.
     */
    private const X_FORWARDED_HEADERS =
        Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    /**
     * Parse TRUSTED_PROXIES into the format accepted by Laravel.
     *
     * Supported values:
     *
     * - empty: trust no explicitly configured proxy;
     * - "*": trust the immediate proxy path;
     * - comma-separated IPv4, IPv6, or CIDR values.
     *
     * @return list<string>|string
     */
    public static function proxies(mixed $value): array|string
    {
        if ($value === null) {
            return [];
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                'TRUSTED_PROXIES must be a string.',
            );
        }

        $value = trim($value);

        if ($value === '') {
            return [];
        }

        if ($value === '*') {
            return '*';
        }

        $proxies = [];

        foreach (explode(',', $value) as $proxy) {
            $proxy = trim($proxy);

            if ($proxy === '') {
                continue;
            }

            if (! self::isValidIpOrCidr($proxy)) {
                throw new InvalidArgumentException(
                    'TRUSTED_PROXIES must contain only valid IP addresses or CIDR ranges.',
                );
            }

            $proxies[] = $proxy;
        }

        return array_values(array_unique($proxies));
    }

    /**
     * Resolve the configured forwarded-header convention to Symfony's bitmask.
     */
    public static function headers(mixed $value): int
    {
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException(
                'TRUSTED_PROXY_HEADERS must be a string.',
            );
        }

        $mode = strtolower(trim($value ?? ''));

        if ($mode === '') {
            $mode = self::MODE_X_FORWARDED;
        }

        return match ($mode) {
            self::MODE_X_FORWARDED => self::X_FORWARDED_HEADERS,
            self::MODE_FORWARDED => Request::HEADER_FORWARDED,
            self::MODE_AWS_ELB => Request::HEADER_X_FORWARDED_AWS_ELB,

            default => throw new InvalidArgumentException(
                'TRUSTED_PROXY_HEADERS must be x_forwarded, forwarded, or aws_elb.',
            ),
        };
    }

    /**
     * Determine whether a value is a valid IPv4, IPv6, or CIDR expression.
     */
    private static function isValidIpOrCidr(string $value): bool
    {
        if (! str_contains($value, '/')) {
            return filter_var($value, FILTER_VALIDATE_IP) !== false;
        }

        [$ipAddress, $prefixLength] = explode('/', $value, 2);

        if (
            filter_var($ipAddress, FILTER_VALIDATE_IP) === false
            || $prefixLength === ''
            || ! ctype_digit($prefixLength)
        ) {
            return false;
        }

        $maximumPrefixLength = filter_var(
            $ipAddress,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV6,
        ) !== false
            ? 128
            : 32;

        return (int) $prefixLength <= $maximumPrefixLength;
    }
}
