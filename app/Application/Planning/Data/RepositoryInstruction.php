<?php

declare(strict_types=1);

namespace App\Application\Planning\Data;

use InvalidArgumentException;

/**
 * One immutable, path-scoped repository instruction file.
 */
final readonly class RepositoryInstruction
{
    public function __construct(
        public string $relativePath,
        public string $scopePath,
        public string $checksumSha256,
        public string $content,
    ) {
        if (
            $this->relativePath === ''
            || str_starts_with($this->relativePath, '/')
            || str_contains('/'.$this->relativePath.'/', '/../')
            || ! str_ends_with($this->relativePath, 'AGENTS.md')
        ) {
            throw new InvalidArgumentException(
                'Repository instruction path must be a relative AGENTS.md path.',
            );
        }

        if (
            $this->scopePath === ''
            || str_starts_with($this->scopePath, '/')
            || str_contains('/'.$this->scopePath.'/', '/../')
        ) {
            throw new InvalidArgumentException(
                'Repository instruction scope must be relative and non-traversing.',
            );
        }

        if (
            preg_match('/\A[a-f0-9]{64}\z/D', $this->checksumSha256) !== 1
            || ! hash_equals(
                $this->checksumSha256,
                hash('sha256', $this->content),
            )
        ) {
            throw new InvalidArgumentException(
                'Repository instruction checksum does not match its content.',
            );
        }

        if (! mb_check_encoding($this->content, 'UTF-8')) {
            throw new InvalidArgumentException(
                'Repository instruction content must be valid UTF-8.',
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(bool $includeContent = true): array
    {
        return array_filter([
            'relative_path' => $this->relativePath,
            'scope_path' => $this->scopePath,
            'checksum_sha256' => $this->checksumSha256,
            'byte_size' => strlen($this->content),
            'content' => $includeContent ? $this->content : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
