<?php

declare(strict_types=1);

use App\Application\Development\Contracts\DevelopmentExecutionProvider;
use App\Application\Development\Data\DevelopmentExecutionRequest;
use App\Application\Development\Data\DevelopmentExecutionResult;
use App\Application\Development\DevelopmentProviderRegistry;
use App\Application\Development\DevelopmentResultValidator;
use App\Application\Development\Providers\ValidatingDevelopmentExecutionProvider;
use App\Application\Executions\Data\ExecutionProviderMetadata;
use App\Application\Planning\Contracts\ExecutionProvider;
use App\Application\Planning\Data\PlanningExecutionRequest;
use App\Application\Planning\Data\PlanningExecutionResult;
use App\Application\Planning\Data\PlanningRoadmapDefinition;
use App\Application\Planning\ExecutionProviderRegistry;
use App\Application\Planning\PlanningResultValidator;
use App\Application\Planning\Providers\ValidatingPlanningExecutionProvider;
use App\Application\QualityAssurance\Contracts\QualityAssuranceExecutionProvider;
use App\Application\QualityAssurance\Data\QaAssessmentResult;
use App\Application\QualityAssurance\Data\QualityAssuranceExecutionRequest;
use App\Application\QualityAssurance\Providers\ValidatingQualityAssuranceExecutionProvider;
use App\Application\QualityAssurance\QaAssessmentValidator;
use App\Application\QualityAssurance\QualityAssuranceProviderRegistry;
use App\Domain\Executions\Exceptions\ProviderResultRejected;
use App\Domain\Executions\ExecutionCapability;
use App\Domain\Executions\ProviderResultRejectionReason;
use App\Domain\Projects\Configuration\ProviderPolicy;
use App\Domain\Projects\Configuration\ReasoningLevel;
use InvalidArgumentException;
use JsonException;
use ReflectionClass;

test(
    'provider rejection reasons classify unsupported schemas and malformed payloads',
    function (): void {
        expect(
            ProviderResultRejectionReason::fromThrowable(
                new InvalidArgumentException(
                    'The provider result schema version is unsupported.',
                ),
            ),
        )->toBe(
            ProviderResultRejectionReason::UnsupportedSchema,
        );

        expect(
            ProviderResultRejectionReason::fromThrowable(
                new JsonException('Malformed provider JSON.'),
            ),
        )->toBe(
            ProviderResultRejectionReason::MalformedPayload,
        );

        expect(
            ProviderResultRejectionReason::fromThrowable(
                new InvalidArgumentException(
                    'The provider result violates target-branch policy.',
                ),
            ),
        )->toBe(
            ProviderResultRejectionReason::ContractOrPolicyViolation,
        );
    },
);

test(
    'planning results with unsupported schema versions are rejected',
    function (): void {
        $request = new PlanningExecutionRequest(
            projectId: 1,
            contextSnapshotId: 1,
            contextFingerprint: str_repeat('a', 64),
            reasoningLevel: ReasoningLevel::Medium,
            documents: [],
        );

        $result = new PlanningExecutionResult(
            schemaVersion: 999,
            outcome: 'blocked',
            documentInventory: [],
            documentSummary: 'Unsupported planning result.',
            architectureConcerns: [],
            securityConcerns: [],
            goal: 'Reject unsupported schema.',
            scope: ['Planning validation.'],
            assumptions: [],
            constraints: ['Schema must be supported.'],
            definitionOfDone: ['Result is rejected.'],
            requiredApprovals: [],
            roadmap: new PlanningRoadmapDefinition(
                phases: [],
                milestones: [],
                tasks: [],
                dependencies: [],
            ),
        );

        $delegate = new class($result) implements ExecutionProvider
        {
            /**
             * Store the deterministic invalid fixture.
             */
            public function __construct(
                private PlanningExecutionResult $result,
            ) {}

            /**
             * Return the fake provider identifier.
             */
            public function id(): string
            {
                return 'simulation';
            }

            /**
             * Return deterministic metadata for the fake simulation provider.
             */
            public function metadata(): ExecutionProviderMetadata
            {
                return new ExecutionProviderMetadata(
                    modelIdentifier: null,
                    protocolVersion: 'test-v1',
                    sandboxProfile: 'test',
                    simulation: true,
                );
            }

            /**
             * Support the canonical planning capability.
             */
            public function supports(string $capability): bool
            {
                return $capability
                    === ExecutionCapability::PlanningGenerate->value;
            }

            /**
             * Return an intentionally unsupported result schema.
             */
            public function execute(
                PlanningExecutionRequest $request,
            ): PlanningExecutionResult {
                return $this->result;
            }
        };

        $provider = new ValidatingPlanningExecutionProvider(
            provider: $delegate,
            validator: new PlanningResultValidator,
            capability: 'planning.roadmap',
        );

        try {
            $provider->execute($request);
        } catch (ProviderResultRejected $exception) {
            expect($exception->providerId)->toBe('simulation')
                ->and($exception->capability)->toBe(
                    'planning.roadmap',
                )
                ->and($exception->resultSchemaVersion)->toBe(999)
                ->and($exception->reason)->toBe(
                    ProviderResultRejectionReason::UnsupportedSchema,
                );

            return;
        }

        throw new RuntimeException(
            'The unsupported planning result was not rejected.',
        );
    },
);

test(
    'development payload decoding failures are rejected',
    function (): void {
        $delegate = new class implements DevelopmentExecutionProvider
        {
            /**
             * Return the fake provider identifier.
             */
            public function id(): string
            {
                return 'simulation';
            }

            /**
             * Return deterministic metadata for the fake simulation provider.
             */
            public function metadata(): ExecutionProviderMetadata
            {
                return new ExecutionProviderMetadata(
                    modelIdentifier: null,
                    protocolVersion: 'test-v1',
                    sandboxProfile: 'test',
                    simulation: true,
                );
            }

            /**
             * Support the canonical development capability.
             */
            public function supports(string $capability): bool
            {
                return $capability
                    === ExecutionCapability::DevelopmentExecute->value;
            }

            /**
             * Simulate malformed provider JSON during normalization.
             */
            public function execute(
                DevelopmentExecutionRequest $request,
            ): DevelopmentExecutionResult {
                throw new JsonException(
                    'Malformed development provider JSON.',
                );
            }
        };

        $provider = new ValidatingDevelopmentExecutionProvider(
            provider: $delegate,
            validator: new DevelopmentResultValidator,
            capability: 'development.simulation',
        );

        /** @var DevelopmentExecutionRequest $request */
        $request = (new ReflectionClass(
            DevelopmentExecutionRequest::class,
        ))->newInstanceWithoutConstructor();

        expect(
            fn (): DevelopmentExecutionResult => $provider->execute(
                $request,
            ),
        )->toThrow(
            ProviderResultRejected::class,
            'Provider result rejected [malformed_payload]',
        );
    },
);

test(
    'qa policy violations are rejected',
    function (): void {
        $delegate = new class implements QualityAssuranceExecutionProvider
        {
            /**
             * Return the fake provider identifier.
             */
            public function id(): string
            {
                return 'simulation';
            }

            /**
             * Return deterministic metadata for the fake simulation provider.
             */
            public function metadata(): ExecutionProviderMetadata
            {
                return new ExecutionProviderMetadata(
                    modelIdentifier: null,
                    protocolVersion: 'test-v1',
                    sandboxProfile: 'test',
                    simulation: true,
                );
            }

            /**
             * Support the canonical QA capability.
             */
            public function supports(string $capability): bool
            {
                return $capability
                    === ExecutionCapability::QualityAssuranceReview->value;
            }

            /**
             * Simulate a policy-invalid QA result.
             */
            public function execute(
                QualityAssuranceExecutionRequest $request,
            ): QaAssessmentResult {
                throw new InvalidArgumentException(
                    'QA assessment target branch must be develop.',
                );
            }
        };

        $provider = new ValidatingQualityAssuranceExecutionProvider(
            provider: $delegate,
            validator: new QaAssessmentValidator,
            capability: 'quality_assurance.simulation',
        );

        /** @var QualityAssuranceExecutionRequest $request */
        $request = (new ReflectionClass(
            QualityAssuranceExecutionRequest::class,
        ))->newInstanceWithoutConstructor();

        expect(
            fn (): QaAssessmentResult => $provider->execute(
                $request,
            ),
        )->toThrow(
            ProviderResultRejected::class,
            'Provider result rejected [contract_or_policy_violation]',
        );
    },
);

test(
    'every provider registry returns a validating decorator',
    function (): void {
        $planningDelegate = new class implements ExecutionProvider
        {
            /**
             * Return the fake provider identifier.
             */
            public function id(): string
            {
                return 'simulation';
            }

            /**
             * Return deterministic metadata for the fake simulation provider.
             */
            public function metadata(): ExecutionProviderMetadata
            {
                return new ExecutionProviderMetadata(
                    modelIdentifier: null,
                    protocolVersion: 'test-v1',
                    sandboxProfile: 'test',
                    simulation: true,
                );
            }

            /**
             * Support the canonical planning capability.
             */
            public function supports(string $capability): bool
            {
                return $capability
                    === ExecutionCapability::PlanningGenerate->value;
            }

            /**
             * Prevent direct execution in this registry test.
             */
            public function execute(
                PlanningExecutionRequest $request,
            ): PlanningExecutionResult {
                throw new RuntimeException('Not executed.');
            }
        };

        $developmentDelegate =
            new class implements DevelopmentExecutionProvider
            {
                /**
                 * Return the fake provider identifier.
                 */
                public function id(): string
                {
                    return 'simulation';
                }

                /**
                 * Return deterministic metadata for the fake simulation provider.
                 */
                public function metadata(): ExecutionProviderMetadata
                {
                    return new ExecutionProviderMetadata(
                        modelIdentifier: null,
                        protocolVersion: 'test-v1',
                        sandboxProfile: 'test',
                        simulation: true,
                    );
                }

                /**
                 * Support the canonical development capability.
                 */
                public function supports(string $capability): bool
                {
                    return $capability
                        === ExecutionCapability::DevelopmentExecute->value;
                }

                /**
                 * Prevent direct execution in this registry test.
                 */
                public function execute(
                    DevelopmentExecutionRequest $request,
                ): DevelopmentExecutionResult {
                    throw new RuntimeException('Not executed.');
                }
            };

        $qaDelegate =
            new class implements QualityAssuranceExecutionProvider
            {
                /**
                 * Return the fake provider identifier.
                 */
                public function id(): string
                {
                    return 'simulation';
                }

                /**
                 * Return deterministic metadata for the fake simulation provider.
                 */
                public function metadata(): ExecutionProviderMetadata
                {
                    return new ExecutionProviderMetadata(
                        modelIdentifier: null,
                        protocolVersion: 'test-v1',
                        sandboxProfile: 'test',
                        simulation: true,
                    );
                }

                /**
                 * Support the canonical QA capability.
                 */
                public function supports(string $capability): bool
                {
                    return $capability
                        === ExecutionCapability::QualityAssuranceReview->value;
                }

                /**
                 * Prevent direct execution in this registry test.
                 */
                public function execute(
                    QualityAssuranceExecutionRequest $request,
                ): QaAssessmentResult {
                    throw new RuntimeException('Not executed.');
                }
            };

        $policy = ProviderPolicy::fromArray([
            'allowed_provider_ids' => ['simulation'],
            'fallback_order' => ['simulation'],
        ]);

        $planningProvider = (
            new ExecutionProviderRegistry(
                providers: [$planningDelegate],
                validator: new PlanningResultValidator,
            )
        )->resolve(
            policy: $policy,
            capability: 'planning.roadmap',
        );

        $developmentProvider = (
            new DevelopmentProviderRegistry(
                providers: [$developmentDelegate],
                validator: new DevelopmentResultValidator,
            )
        )->resolve(
            fallbackOrder: ['simulation'],
            capability: 'development.simulation',
        );

        $qaProvider = (
            new QualityAssuranceProviderRegistry(
                providers: [$qaDelegate],
                validator: new QaAssessmentValidator,
            )
        )->resolve(
            fallbackOrder: ['simulation'],
            capability: 'quality_assurance.simulation',
        );

        expect($planningProvider)->toBeInstanceOf(
            ValidatingPlanningExecutionProvider::class,
        )->and($developmentProvider)->toBeInstanceOf(
            ValidatingDevelopmentExecutionProvider::class,
        )->and($qaProvider)->toBeInstanceOf(
            ValidatingQualityAssuranceExecutionProvider::class,
        );
    },
);
