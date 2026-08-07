<?php

declare(strict_types=1);

namespace App\Application\Executions\Data;

use App\Application\Executions\Contracts\DescribesExecutionProvider;
use App\Domain\Executions\ExecutionCapability;
use InvalidArgumentException;
use LogicException;

/**
 * Captures the exact provider selected for one execution attempt.
 */
final readonly class ProviderSelection
{
    /**
     * Create one immutable selected-provider snapshot.
     */
    public function __construct(
        public string $providerId,
        public ?string $modelIdentifier,
        public string $protocolVersion,
        public string $sandboxProfile,
        public ExecutionCapability $effectiveCapability,
        public string $selectionSource,
        public bool $simulation,
    ) {
        $this->assertRequiredText(
            value: $this->providerId,
            name: 'provider identifier',
            maximum: 100,
        );

        $this->assertRequiredText(
            value: $this->selectionSource,
            name: 'provider selection source',
            maximum: 100,
        );
    }

    /**
     * Build a selected-provider snapshot from a resolved typed provider.
     */
    public static function fromProvider(
        string $requestedCapability,
        DescribesExecutionProvider $provider,
        string $selectionSource,
    ): self {
        $effectiveCapability = ExecutionCapability::fromStored(
            $requestedCapability,
        );

        if (! $provider->supports($effectiveCapability->value)) {
            throw new LogicException(sprintf(
                'Provider [%s] does not support capability [%s].',
                $provider->id(),
                $effectiveCapability->value,
            ));
        }

        $metadata = $provider->metadata();

        return new self(
            providerId: $provider->id(),
            modelIdentifier: $metadata->modelIdentifier,
            protocolVersion: $metadata->protocolVersion,
            sandboxProfile: $metadata->sandboxProfile,
            effectiveCapability: $effectiveCapability,
            selectionSource: $selectionSource,
            simulation: $metadata->simulation,
        );
    }

    /**
     * Validate required normalized selection metadata.
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
                'Execution %s is invalid.',
                $name,
            ));
        }
    }
}
