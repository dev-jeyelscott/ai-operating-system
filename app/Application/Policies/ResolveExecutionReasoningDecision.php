<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Application\Policies\Data\ReasoningResolutionContext;
use App\Application\Policies\Exceptions\PolicyDecisionConflict;
use App\Domain\Policies\ReasoningEscalationReason;
use App\Models\Execution;
use App\Models\PolicyDecision;
use App\Models\ProjectConfiguration;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

/**
 * Resolves and persists one immutable reasoning decision for an execution.
 */
final readonly class ResolveExecutionReasoningDecision
{
    /**
     * Inject the deterministic pure resolver.
     */
    public function __construct(
        private ReasoningResolver $reasoningResolver,
    ) {}

    /**
     * Resolve policy under locks and return the idempotently persisted decision.
     *
     * @throws JsonException
     */
    public function resolve(
        Execution $execution,
        ReasoningResolutionContext $context,
    ): PolicyDecision {
        if (! $execution->exists) {
            throw new InvalidArgumentException(
                'A persisted execution is required to record a policy decision.',
            );
        }

        return DB::transaction(
            function () use ($execution, $context): PolicyDecision {
                /** @var Execution $lockedExecution */
                $lockedExecution = Execution::query()
                    ->forProject($execution->project_id)
                    ->whereKey($execution->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                /** @var ProjectConfiguration|null $configuration */
                $configuration = ProjectConfiguration::query()
                    ->where('project_id', $lockedExecution->project_id)
                    ->lockForUpdate()
                    ->first();

                $resolution = $this->reasoningResolver->resolve(
                    context: $context,
                    projectDefault: $configuration?->default_reasoning,
                );

                if (
                    $lockedExecution->requested_reasoning_level
                    !== $resolution->requestedReasoning
                ) {
                    throw PolicyDecisionConflict::requestedReasoningMismatch(
                        executionId: $lockedExecution->id,
                        executionRequested: $lockedExecution
                            ->requested_reasoning_level,
                        resolvedRequested: $resolution->requestedReasoning,
                    );
                }

                $inputSnapshot = $context->toInputSnapshot(
                    projectDefault: $configuration?->default_reasoning,
                    projectConfigurationRevision: $configuration?->revision,
                );

                $inputFingerprint = $this->inputFingerprint($inputSnapshot);

                /** @var PolicyDecision|null $existingDecision */
                $existingDecision = PolicyDecision::query()
                    ->forProject($lockedExecution->project_id)
                    ->where('execution_id', $lockedExecution->id)
                    ->where(
                        'decision_type',
                        PolicyDecision::REASONING_RESOLUTION,
                    )
                    ->first();

                if ($existingDecision !== null) {
                    if (
                        $existingDecision->policy_version
                            !== ReasoningResolver::POLICY_VERSION
                        || $existingDecision->input_fingerprint
                            !== $inputFingerprint
                    ) {
                        throw PolicyDecisionConflict::replayedWithDifferentInputs(
                            executionId: $lockedExecution->id,
                        );
                    }

                    return $existingDecision;
                }

                $decision = PolicyDecision::query()->create([
                    'project_id' => $lockedExecution->project_id,
                    'execution_id' => $lockedExecution->id,
                    'decision_type' => PolicyDecision::REASONING_RESOLUTION,
                    'policy_version' => ReasoningResolver::POLICY_VERSION,
                    'requested_reasoning_level' => $resolution
                        ->requestedReasoning,
                    'requested_reasoning_source' => $resolution
                        ->requestedSource,
                    'effective_reasoning_level' => $resolution
                        ->effectiveReasoning,
                    'reasoning_resolution_source' => $resolution
                        ->resolutionSource,
                    'reasoning_escalation_reason' => $resolution
                        ->escalationReason,
                    'reasoning_escalation_reasons' => array_map(
                        static fn (ReasoningEscalationReason $reason): string => $reason->value,
                        $resolution->mandatoryEscalationReasons,
                    ),
                    'input_snapshot' => $inputSnapshot,
                    'input_fingerprint' => $inputFingerprint,
                ]);

                $decision->refresh();

                return $decision;
            },
            attempts: 3,
        );
    }

    /**
     * Hash a canonical resolver input document for conflict-safe replay.
     *
     * @param  array<string, mixed>  $inputSnapshot
     *
     * @throws JsonException
     */
    private function inputFingerprint(array $inputSnapshot): string
    {
        $canonicalInput = [
            'decision_type' => PolicyDecision::REASONING_RESOLUTION,
            'policy_version' => ReasoningResolver::POLICY_VERSION,
            'inputs' => $inputSnapshot,
        ];

        return hash(
            'sha256',
            json_encode(
                $canonicalInput,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
        );
    }
}
