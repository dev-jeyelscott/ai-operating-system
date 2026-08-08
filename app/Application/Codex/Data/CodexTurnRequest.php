<?php

declare(strict_types=1);

namespace App\Application\Codex\Data;

use InvalidArgumentException;

/**
 * Application-owned, schema-constrained input for one Codex turn.
 */
final readonly class CodexTurnRequest
{
    /**
     * Validated, non-empty provider input items.
     *
     * @var non-empty-list<array<string, mixed>>
     */
    public array $input;

    /**
     * Validated JSON Schema supplied to the provider.
     *
     * @var array<string, mixed>
     */
    public array $outputSchema;

    /**
     * Create and validate one schema-constrained Codex turn request.
     *
     * @param  array<array-key, mixed>  $input
     * @param  array<string, mixed>  $outputSchema
     */
    public function __construct(
        public string $threadId,
        array $input,
        public string $reasoningEffort,
        array $outputSchema,
    ) {
        if ($this->threadId === '') {
            throw new InvalidArgumentException(
                'Codex turn thread identifier is invalid.',
            );
        }

        self::assertValidInput($input);

        if (! in_array(
            $this->reasoningEffort,
            ['low', 'medium', 'high'],
            true,
        )) {
            throw new InvalidArgumentException(
                'Codex reasoning effort is invalid.',
            );
        }

        if (($outputSchema['type'] ?? null) !== 'object') {
            throw new InvalidArgumentException(
                'Codex output schema must describe an object.',
            );
        }

        $this->input = $input;
        $this->outputSchema = $outputSchema;
    }

    /**
     * Convert the request into Codex App Server turn/start parameters.
     *
     * @return array<string, mixed>
     */
    public function toProtocolParams(): array
    {
        return [
            'threadId' => $this->threadId,
            'input' => $this->input,
            'effort' => $this->reasoningEffort,
            'outputSchema' => $this->outputSchema,
        ];
    }

    /**
     * Validate and narrow raw input into a non-empty list of input objects.
     *
     * @param  array<array-key, mixed>  $input
     *
     * @phpstan-assert non-empty-list<array<string, mixed>> $input
     */
    private static function assertValidInput(array $input): void
    {
        if ($input === [] || ! array_is_list($input)) {
            throw new InvalidArgumentException(
                'Codex turn input is invalid.',
            );
        }

        foreach ($input as $item) {
            if (
                ! is_array($item)
                || ! is_string($item['type'] ?? null)
            ) {
                throw new InvalidArgumentException(
                    'Codex turn input contains an invalid item.',
                );
            }

            foreach (array_keys($item) as $key) {
                if (! is_string($key)) {
                    throw new InvalidArgumentException(
                        'Codex turn input item keys must be strings.',
                    );
                }
            }
        }
    }
}
