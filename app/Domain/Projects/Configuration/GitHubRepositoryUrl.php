<?php

declare(strict_types=1);

namespace App\Domain\Projects\Configuration;

/**
 * Validates the syntax of repository URLs supported by schema version 1.
 *
 * This validator is intentionally local and deterministic. It never performs
 * DNS resolution, HTTP requests, repository cloning, or provider API calls.
 */
final class GitHubRepositoryUrl
{
    private const HOST = 'github.com';

    /**
     * Determine whether the value is a credential-free GitHub HTTPS repository URL.
     */
    public static function isValid(string $value): bool
    {
        if ($value === '' || $value !== trim($value)) {
            return false;
        }

        $parts = parse_url($value);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? null;

        if (
            ! is_string($scheme)
            || strtolower($scheme) !== 'https'
            || ! is_string($host)
            || strtolower($host) !== self::HOST
            || ! is_string($path)
        ) {
            return false;
        }

        /*
         * Credentials, ports, query strings, and fragments do not belong in
         * configuration metadata. They could also accidentally persist secrets.
         */
        foreach (
            ['user', 'pass', 'port', 'query', 'fragment'] as $forbiddenPart
        ) {
            if (array_key_exists($forbiddenPart, $parts)) {
                return false;
            }
        }

        if ($path === '' || str_contains($path, '//')) {
            return false;
        }

        $repositoryPath = trim($path, '/');

        /*
         * Encoded path characters are rejected instead of decoded. This prevents
         * encoded slashes or path traversal values from bypassing segment checks.
         */
        if (
            $repositoryPath === ''
            || preg_match('/%[0-9A-Fa-f]{2}/', $repositoryPath) === 1
        ) {
            return false;
        }

        $segments = explode('/', $repositoryPath);

        /*
         * A repository URL must contain exactly an owner and repository name.
         * Repository subpages such as /issues or /pulls are not accepted.
         */
        if (count($segments) !== 2) {
            return false;
        }

        [$owner, $repository] = $segments;

        /*
         * GitHub HTTPS clone URLs may include a terminal ".git" suffix.
         * Validation ignores that suffix but persistence preserves the submitted URL.
         */
        if (str_ends_with($repository, '.git')) {
            $repository = substr($repository, 0, -4);
        }

        /*
         * GitHub account names are limited to alphanumeric characters and
         * hyphens, cannot begin or end with a hyphen, and are at most 39 chars.
         */
        if (
            preg_match(
                '/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?\z/',
                $owner,
            ) !== 1
        ) {
            return false;
        }

        /*
         * Repository names are format-validated only. This does not verify that
         * the repository exists or that the current user can access it.
         */
        if (
            $repository === ''
            || in_array($repository, ['.', '..'], true)
            || preg_match(
                '/\A[A-Za-z0-9._-]{1,100}\z/',
                $repository,
            ) !== 1
        ) {
            return false;
        }

        return true;
    }
}
