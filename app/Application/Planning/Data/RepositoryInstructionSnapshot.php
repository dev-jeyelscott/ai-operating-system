<?php

declare(strict_types=1);

namespace App\Application\Planning\Data;

use InvalidArgumentException;

/**
 * Immutable repository guidance selected from one exact commit.
 */
final readonly class RepositoryInstructionSnapshot
{
    /**
     * Validated repository instruction entries.
     *
     * @var list<RepositoryInstruction>
     */
    public array $instructions;

    /**
     * Create one validated immutable repository-instruction snapshot.
     *
     * @param  array<array-key, mixed>  $instructions
     */
    public function __construct(
        public string $repositoryUrl,
        public string $commitSha,
        array $instructions,
    ) {
        if (! filter_var(
            $this->repositoryUrl,
            FILTER_VALIDATE_URL,
        )) {
            throw new InvalidArgumentException(
                'Repository instruction snapshot URL is invalid.',
            );
        }

        if (
            preg_match(
                '/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/D',
                $this->commitSha,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Repository instruction snapshot commit must be a full SHA.',
            );
        }

        self::assertValidInstructions($instructions);

        $this->instructions = $instructions;
    }

    /**
     * Validate and narrow raw entries into repository instructions.
     *
     * @param  array<array-key, mixed>  $instructions
     *
     * @phpstan-assert list<RepositoryInstruction> $instructions
     */
    private static function assertValidInstructions(
        array $instructions,
    ): void {
        if (! array_is_list($instructions)) {
            throw new InvalidArgumentException(
                'Repository instruction snapshot entries must be a list.',
            );
        }

        foreach ($instructions as $instruction) {
            if (! $instruction instanceof RepositoryInstruction) {
                throw new InvalidArgumentException(
                    'Repository instruction snapshot contains an invalid entry.',
                );
            }
        }
    }
}
