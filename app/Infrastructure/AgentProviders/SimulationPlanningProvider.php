<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentProviders;

use App\Application\Planning\Contracts\ExecutionProvider;
use App\Application\Planning\Data\PlanningAcceptanceCriterion;
use App\Application\Planning\Data\PlanningDiagnostic;
use App\Application\Planning\Data\PlanningDocument;
use App\Application\Planning\Data\PlanningExecutionRequest;
use App\Application\Planning\Data\PlanningExecutionResult;
use App\Application\Planning\Data\PlanningMilestone;
use App\Application\Planning\Data\PlanningPhase;
use App\Application\Planning\Data\PlanningRoadmapDefinition;
use App\Application\Planning\Data\PlanningSourceReference;
use App\Application\Planning\Data\PlanningTask;
use InvalidArgumentException;

/**
 * Produces seed-stable planning results without claiming verified evidence.
 */
final class SimulationPlanningProvider implements ExecutionProvider
{
    public const string HAPPY_PATH = 'happy_path';

    public const string MISSING_DOCUMENTS = 'missing_documents';

    public const string CONFLICTS = 'conflicts';

    public const string BLOCKED = 'blocked';

    public const string HUMAN_DECISION_REQUIRED = 'human_decision_required';

    /** @var list<string> */
    public const array SUPPORTED_SCENARIOS = [
        self::HAPPY_PATH,
        self::MISSING_DOCUMENTS,
        self::CONFLICTS,
        self::BLOCKED,
        self::HUMAN_DECISION_REQUIRED,
    ];

    /**
     * Return the stable simulation provider identifier.
     */
    public function id(): string
    {
        return 'simulation';
    }

    /**
     * Support only deterministic roadmap planning.
     */
    public function supports(string $capability): bool
    {
        return $capability === 'planning.roadmap';
    }

    /**
     * Execute one supported deterministic planning scenario.
     */
    public function execute(
        PlanningExecutionRequest $request,
    ): PlanningExecutionResult {
        if (! in_array(
            $request->scenario,
            self::SUPPORTED_SCENARIOS,
            true,
        )) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported planning simulation scenario [%s]. Supported scenarios: %s.',
                $request->scenario,
                implode(', ', self::SUPPORTED_SCENARIOS),
            ));
        }

        $sourceReferences = $request->documents;

        [$gaps, $conflicts, $risks, $humanDecisionRequired] = match (
            $request->scenario
        ) {
            self::MISSING_DOCUMENTS => [
                ['Required source documents are missing.'],
                [],
                [],
                false,
            ],
            self::CONFLICTS => [
                [],
                ['Approved documents contain conflicting requirements.'],
                [],
                false,
            ],
            self::BLOCKED => [
                ['Planning is blocked by an explicit simulation scenario.'],
                [],
                [],
                false,
            ],
            self::HUMAN_DECISION_REQUIRED => [
                [],
                [],
                ['A consequential ambiguity requires an owner decision.'],
                true,
            ],
            self::HAPPY_PATH => [[], [], [], false],
        };

        $isMissingDocuments = $request->scenario === self::MISSING_DOCUMENTS
            || $sourceReferences === [];

        if ($isMissingDocuments) {
            return new PlanningExecutionResult(
                schemaVersion: PlanningExecutionResult::SCHEMA_VERSION,
                outcome: 'blocked',
                documentInventory: [],
                documentSummary: 'No approved documents were available for planning.',
                architectureConcerns: [],
                securityConcerns: [],
                goal: 'Produce a roadmap from approved project context.',
                scope: [
                    'Planning cannot proceed without approved documents.',
                ],
                assumptions: [],
                constraints: [
                    'Only approved document versions may be used.',
                ],
                definitionOfDone: [
                    'Required documents are approved and planning is regenerated.',
                ],
                requiredApprovals: ['roadmap'],
                roadmap: new PlanningRoadmapDefinition([], [], [], []),
                gaps: $gaps === []
                    ? ['Required source documents are missing.']
                    : $gaps,
                conflicts: $conflicts,
                risks: $risks,
                humanDecisionRequired: $humanDecisionRequired,
                diagnostics: [
                    new PlanningDiagnostic(
                        code: 'planning.missing_documents',
                        category: 'deterministic_blocker',
                        message: 'Required approved source documents are missing.',
                    ),
                ],
            );
        }

        $criterion = new PlanningAcceptanceCriterion(
            stableId: 'criterion-source-coverage',
            description: 'Planning result is reviewed against every approved source reference.',
            sourceReferences: $sourceReferences,
        );

        $task = new PlanningTask(
            stableId: 'task-plan-'.substr(
                hash(
                    'sha256',
                    $request->contextFingerprint.'|'.$request->seed,
                ),
                0,
                12,
            ),
            title: 'Plan delivery from approved project context',
            objective: 'Produce a simulated and unverified delivery plan from the immutable approved documents.',
            phaseId: 'phase-discovery',
            milestoneId: 'milestone-plan',
            ticketType: 'feature',
            scope: [
                'included' => ['planning'],
                'excluded' => [
                    'implementation',
                    'CI',
                    'QA',
                    'merge',
                    'deployment',
                ],
            ],
            acceptanceCriteria: [$criterion],
            sourceReferences: $sourceReferences,
            evidenceRequirements: [
                'human roadmap approval',
                'simulated/unverified planning record',
            ],
            priority: 'high',
            risk: 'medium',
            reasoningLevel: $request->reasoningLevel,
            logicalAgent: 'project_manager',
            estimatedComplexity: 3,
            humanApprovalRequired: true,
            reasoning: 'The immutable approved document set is the authoritative planning source.',
        );

        $diagnosticMessage = $conflicts[0] ?? $gaps[0] ?? null;

        return new PlanningExecutionResult(
            schemaVersion: PlanningExecutionResult::SCHEMA_VERSION,
            outcome: $gaps !== [] || $conflicts !== []
                ? 'blocked'
                : 'publishable',
            documentInventory: array_map(
                static fn (
                    PlanningSourceReference $reference,
                ): PlanningDocument => new PlanningDocument(
                    source: $reference,
                    classification: 'approved_project_context',
                    summary: 'Approved document version included in the immutable planning context.',
                ),
                $sourceReferences,
            ),
            documentSummary: sprintf(
                '%d approved document version(s) were analyzed.',
                count($sourceReferences),
            ),
            architectureConcerns: [],
            securityConcerns: [],
            goal: 'Produce an approved, traceable delivery roadmap.',
            scope: ['Layer 1 planning and project intelligence.'],
            assumptions: ['Approved documents are authoritative.'],
            constraints: [
                'Simulation output is unverified and cannot represent implementation evidence.',
            ],
            definitionOfDone: [
                'Every task is traceable and the roadmap is approved.',
            ],
            requiredApprovals: ['roadmap'],
            roadmap: new PlanningRoadmapDefinition(
                phases: [
                    new PlanningPhase(
                        'phase-discovery',
                        'Discovery',
                    ),
                ],
                milestones: [
                    new PlanningMilestone(
                        'milestone-plan',
                        'Roadmap ready',
                        'phase-discovery',
                    ),
                ],
                tasks: [$task],
                dependencies: [],
            ),
            gaps: $gaps,
            conflicts: $conflicts,
            risks: $risks,
            humanDecisionRequired: $humanDecisionRequired,
            diagnostics: $diagnosticMessage !== null
                ? [
                    new PlanningDiagnostic(
                        code: $conflicts !== []
                            ? 'planning.source_conflict'
                            : 'planning.blocked',
                        category: 'deterministic_blocker',
                        message: $diagnosticMessage,
                    ),
                ]
                : [],
        );
    }
}
