<?php

declare(strict_types=1);

namespace App\Application\Executions\Data;

use InvalidArgumentException;

/**
 * Contains provider-owned metadata that is independent of one execution.
 */
final readonly class ExecutionProviderMetadata
{
    /**
     * Create validated immutable provider metadata.
     */
    public function __construct(
        public ?string $modelIdentifier,
        public string $protocolVersion,
        public string $sandboxProfile,
        public bool $simulation,
    ) {
        $this->assertOptionalText(
            value: $this->modelIdentifier,
            name: 'model identifier',
            maximum: 191,
        );

        $this->assertRequiredText(
            value: $this->protocolVersion,
            name: 'protocol version',
            maximum: 100,
        );

        $this->assertRequiredText(
            value: $this->sandboxProfile,
            name: 'sandbox profile',
            maximum: 100,
        );
    }

    /**
     * Validate required normalized provider metadata.
     */
    private function assertRequiredText(
        string $value,
        string $name,
        int $maximum,
    ): void {
        if (
            $value === ''
            || trim($value) !== $value
            || strlen($value) > $maximum
        ) {
            throw new InvalidArgumentException(sprintf(
                'Execution provider %s is invalid.',
                $name,
            ));
        }
    }

    /**
     * Validate optional normalized provider metadata.
     */
    private function assertOptionalText(
        ?string $value,
        string $name,
        int $maximum,
    ): void {
        if ($value === null) {
            return;
        }

        $this->assertRequiredText(
            value: $value,
            name: $name,
            maximum: $maximum,
        );
    }
}
