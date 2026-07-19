<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use InvalidArgumentException;

/**
 * Represents one normalized Notion database identifier.
 */
final readonly class NotionDatabaseId
{
    /**
     * Store the normalized dashed UUID.
     */
    private function __construct(
        private string $value,
    ) {}

    /**
     * Parse a raw UUID, compact UUID, or supported Notion database URL.
     */
    public static function from(string $input): self
    {
        $candidate = trim($input);

        if ($candidate === '') {
            throw new InvalidArgumentException(
                'The Notion database identifier is required.',
            );
        }

        $hexadecimal = filter_var(
            $candidate,
            FILTER_VALIDATE_URL,
        ) !== false
            ? self::hexadecimalFromUrl($candidate)
            : str_replace('-', '', strtolower($candidate));

        if (
            preg_match(
                '/\A[0-9a-f]{32}\z/D',
                $hexadecimal,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'The Notion database identifier must be a valid UUID or Notion database URL.',
            );
        }

        return new self(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hexadecimal, 0, 8),
            substr($hexadecimal, 8, 4),
            substr($hexadecimal, 12, 4),
            substr($hexadecimal, 16, 4),
            substr($hexadecimal, 20, 12),
        ));
    }

    /**
     * Return the normalized dashed UUID.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Compare two normalized identifiers.
     */
    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    /**
     * Extract the final 32-character identifier from a trusted Notion URL.
     */
    private static function hexadecimalFromUrl(string $url): string
    {
        $host = strtolower(
            (string) parse_url($url, PHP_URL_HOST),
        );

        if (! self::isSupportedNotionHost($host)) {
            throw new InvalidArgumentException(
                'The database URL must use a supported Notion host.',
            );
        }

        $path = strtolower(
            (string) parse_url($url, PHP_URL_PATH),
        );

        /*
         * Notion URLs commonly place the UUID after a hyphenated page title.
         * Remove separators and use the final 32-character hexadecimal match.
         */
        preg_match_all(
            '/[0-9a-f]{32}/',
            str_replace('-', '', $path),
            $matches,
        );

        $identifiers = $matches[0] ?? [];

        if ($identifiers === []) {
            throw new InvalidArgumentException(
                'The Notion database URL does not contain a database identifier.',
            );
        }

        return (string) end($identifiers);
    }

    /**
     * Prevent arbitrary external URLs from being accepted as Notion metadata.
     */
    private static function isSupportedNotionHost(string $host): bool
    {
        return in_array($host, [
            'notion.so',
            'www.notion.so',
            'app.notion.com',
            'notion.site',
        ], true)
            || str_ends_with($host, '.notion.site');
    }
}
