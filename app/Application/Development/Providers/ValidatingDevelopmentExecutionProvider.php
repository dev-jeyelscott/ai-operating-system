<?php

declare(strict_types=1);

namespace App\Application\Development\Providers;

use App\Application\Development\Contracts\DevelopmentExecutionProvider;
use App\Application\Development\Data\DevelopmentExecutionRequest;
use App\Application\Development\Data\DevelopmentExecutionResult;
use App\Application\Development\DevelopmentResultValidator;
use App\Application\Executions\Data\ExecutionProviderMetadata;
use App\Domain\Executions\Exceptions\ProviderResultRejected;
use InvalidArgumentException;
use JsonException;
use TypeError;
use ValueError;

/**
 * Enforces development-result validation at the provider boundary.
 */
final readonly class ValidatingDevelopmentExecutionProvider implements DevelopmentExecutionProvider
{
    /**
     * Wrap one concrete provider with the deterministic development validator.
     */
    public function __construct(
        private DevelopmentExecutionProvider $provider,
        private DevelopmentResultValidator $validator,
        private string $capability,
    ) {}

    /**
     * Return the underlying provider's stable identifier.
     */
    public function id(): string
    {
        return $this->provider->id();
    }

    /**
     * Return metadata from the wrapped provider.
     */
    public function metadata(): ExecutionProviderMetadata
    {
        return $this->provider->metadata();
    }

    /**
     * Delegate capability discovery to the underlying provider.
     */
    public function supports(string $capability): bool
    {
        return $this->provider->supports($capability);
    }

    /**
     * Execute and return only a schema-valid, policy-valid development result.
     */
    public function execute(
        DevelopmentExecutionRequest $request,
    ): DevelopmentExecutionResult {
        $result = null;

        try {
            $result = $this->provider->execute($request);

            $this->validator->validateResult($result);

            return $result;
        } catch (ProviderResultRejected $exception) {
            throw $exception;
        } catch (
            InvalidArgumentException
            |JsonException
            |TypeError
            |ValueError $exception
        ) {
            throw ProviderResultRejected::fromThrowable(
                providerId: $this->provider->id(),
                capability: $this->capability,
                resultSchemaVersion: $result?->schemaVersion,
                exception: $exception,
            );
        }
    }
}
