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
     * @param  list<array<string, mixed>>  $input
     * @param  array<string, mixed>  $outputSchema
     */
    public function __construct(
        public string $threadId,
        public array $input,
        public string $reasoningEffort,
        public array $outputSchema,
    ) {
        if ($this->threadId === '' || $this->input === [] || ! array_is_list($this->input)) {
            throw new InvalidArgumentException('Codex turn input is invalid.');
        }

        if (! in_array($this->reasoningEffort, ['low', 'medium', 'high'], true)) {
            throw new InvalidArgumentException('Codex reasoning effort is invalid.');
        }

        if (($this->outputSchema['type'] ?? null) !== 'object') {
            throw new InvalidArgumentException('Codex output schema must describe an object.');
        }

        foreach ($this->input as $item) {
            if (! is_array($item) || ! is_string($item['type'] ?? null)) {
                throw new InvalidArgumentException('Codex turn input contains an invalid item.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toProtocolParams(): array
    {
        return [
            'threadId' => $this->threadId,
            'input' => $this->input,
            'effort' => $this->reasoningEffort,
            'outputSchema' => $this->outputSchema,
        ];
    }
}
