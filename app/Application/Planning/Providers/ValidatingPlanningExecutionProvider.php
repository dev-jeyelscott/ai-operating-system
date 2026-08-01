<?php

declare(strict_types=1);

namespace App\Application\Planning\Providers;

use App\Application\Planning\Contracts\ExecutionProvider;
use App\Application\Planning\Data\PlanningExecutionRequest;
use App\Application\Planning\Data\PlanningExecutionResult;
use App\Application\Planning\PlanningResultValidator;
use App\Domain\Executions\Exceptions\ProviderResultRejected;
use InvalidArgumentException;
use JsonException;
use TypeError;
use ValueError;

/**
 * Enforces planning-result validation at the provider boundary.
 */
final readonly class ValidatingPlanningExecutionProvider implements ExecutionProvider
{
    /**
     * Wrap one concrete provider with the deterministic planning validator.
     */
    public function __construct(
        private ExecutionProvider $provider,
        private PlanningResultValidator $validator,
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
     * Delegate capability discovery to the underlying provider.
     */
    public function supports(string $capability): bool
    {
        return $this->provider->supports($capability);
    }

    /**
     * Execute and return only a schema-valid, policy-valid planning result.
     */
    public function execute(
        PlanningExecutionRequest $request,
    ): PlanningExecutionResult {
        $result = null;

        try {
            $result = $this->provider->execute($request);

            $this->validator->validate(
                result: $result,
                request: $request,
            );

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
