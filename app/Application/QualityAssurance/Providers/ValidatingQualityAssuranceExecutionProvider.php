<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance\Providers;

use App\Application\Executions\Data\ExecutionProviderMetadata;
use App\Application\QualityAssurance\Contracts\QualityAssuranceExecutionProvider;
use App\Application\QualityAssurance\Data\QaAssessmentResult;
use App\Application\QualityAssurance\Data\QualityAssuranceExecutionRequest;
use App\Application\QualityAssurance\QaAssessmentValidator;
use App\Domain\Executions\Exceptions\ProviderResultRejected;
use InvalidArgumentException;
use JsonException;
use TypeError;
use ValueError;

/**
 * Enforces QA-result validation at the provider boundary.
 */
final readonly class ValidatingQualityAssuranceExecutionProvider implements QualityAssuranceExecutionProvider
{
    /**
     * Wrap one concrete provider with the deterministic QA validator.
     */
    public function __construct(
        private QualityAssuranceExecutionProvider $provider,
        private QaAssessmentValidator $validator,
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
     * Execute and return only a schema-valid, policy-valid QA result.
     */
    public function execute(
        QualityAssuranceExecutionRequest $request,
    ): QaAssessmentResult {
        $result = null;

        try {
            $result = $this->provider->execute($request);

            $this->validator->validateAssessment($result);

            return $result;
        } catch (ProviderResultRejected $exception) {
            throw $exception;
        } catch (
            InvalidArgumentException
            | JsonException
            | TypeError
            | ValueError $exception
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
