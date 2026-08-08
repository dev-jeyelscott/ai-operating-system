<?php

declare(strict_types=1);

namespace App\Application\Planning\Data;

use InvalidArgumentException;

/**
 * Immutable repository guidance selected from one exact commit.
 */
final readonly class RepositoryInstructionSnapshot
{
    /** @param list<RepositoryInstruction> $instructions */
    public function __construct(
        public string $repositoryUrl,
        public string $commitSha,
        public array $instructions,
    ) {
        if (! filter_var($this->repositoryUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException(
                'Repository instruction snapshot URL is invalid.',
            );
        }

        if (preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/D', $this->commitSha) !== 1) {
            throw new InvalidArgumentException(
                'Repository instruction snapshot commit must be a full SHA.',
            );
        }

        foreach ($this->instructions as $instruction) {
            if (! $instruction instanceof RepositoryInstruction) {
                throw new InvalidArgumentException(
                    'Repository instruction snapshot contains an invalid entry.',
                );
            }
        }
    }
}
