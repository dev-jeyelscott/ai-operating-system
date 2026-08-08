<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Application\Planning\Data\PlanningExecutionResult;
use App\Domain\Approvals\ApprovalType;
use App\Domain\Projects\Configuration\ReasoningLevel;

/**
 * Returns the versioned JSON Schema sent to Codex for Layer 1 planning.
 */
final readonly class PlanningResultJsonSchema
{
    /** @return array<string, mixed> */
    public function value(): array
    {
        $sourceReference = $this->object(
            required: [
                'document_id',
                'document_version_id',
                'version',
                'checksum_sha256',
            ],
            properties: [
                'document_id' => $this->positiveInteger(),
                'document_version_id' => $this->positiveInteger(),
                'version' => $this->positiveInteger(),
                'checksum_sha256' => [
                    'type' => 'string',
                    'pattern' => '^[a-f0-9]{64}$',
                ],
            ],
        );

        $criterion = $this->object(
            required: [
                'stable_id',
                'description',
                'source_references',
            ],
            properties: [
                'stable_id' => $this->stableId(),
                'description' => $this->text(),
                'source_references' => $this->list(
                    $sourceReference,
                    minimum: 1,
                ),
            ],
        );

        $task = $this->object(
            required: [
                'stable_id',
                'title',
                'objective',
                'phase_id',
                'milestone_id',
                'ticket_type',
                'scope',
                'acceptance_criteria',
                'source_references',
                'evidence_requirements',
                'priority',
                'risk',
                'reasoning_level',
                'logical_agent',
                'estimated_complexity',
                'human_approval_required',
                'reasoning',
            ],
            properties: [
                'stable_id' => $this->stableId(),
                'title' => $this->text(),
                'objective' => $this->text(),
                'phase_id' => $this->stableId(),
                'milestone_id' => $this->stableId(),
                'ticket_type' => [
                    'type' => 'string',
                    'enum' => [
                        'feature',
                        'bug',
                        'enhancement',
                        'change',
                        'technical_debt',
                    ],
                ],
                'scope' => $this->object(
                    required: ['included', 'excluded'],
                    properties: [
                        'included' => $this->stringList(1),
                        'excluded' => $this->stringList(),
                    ],
                ),
                'acceptance_criteria' => $this->list($criterion, 1),
                'source_references' => $this->list($sourceReference, 1),
                'evidence_requirements' => $this->stringList(1),
                'priority' => $this->riskEnum(),
                'risk' => $this->riskEnum(),
                'reasoning_level' => [
                    'type' => 'string',
                    'enum' => array_map(
                        static fn (ReasoningLevel $level): string => $level->value,
                        ReasoningLevel::cases(),
                    ),
                ],
                'logical_agent' => [
                    'type' => 'string',
                    'enum' => [
                        'product_manager',
                        'business_analyst',
                        'project_manager',
                        'software_architect',
                        'security_architect',
                        'database_architect',
                        'documentation_analyst',
                        'backend_engineer',
                        'frontend_engineer',
                        'database_engineer',
                        'test_engineer',
                        'devops_engineer',
                    ],
                ],
                'estimated_complexity' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 13,
                ],
                'human_approval_required' => ['type' => 'boolean'],
                'reasoning' => $this->text(),
            ],
        );

        $diagnosticValue = [
            'anyOf' => [
                ['type' => 'string', 'maxLength' => $this->maximumStringBytes()],
                ['type' => 'integer'],
                ['type' => 'number'],
                ['type' => 'boolean'],
                ['type' => 'null'],
                $this->list([
                    'anyOf' => [
                        ['type' => 'string', 'maxLength' => $this->maximumStringBytes()],
                        ['type' => 'integer'],
                        ['type' => 'number'],
                        ['type' => 'boolean'],
                        ['type' => 'null'],
                    ],
                ]),
            ],
        ];

        return $this->object(
            required: [
                'schema_version',
                'outcome',
                'document_inventory',
                'document_summary',
                'architecture_concerns',
                'security_concerns',
                'goal',
                'scope',
                'assumptions',
                'constraints',
                'definition_of_done',
                'required_approvals',
                'roadmap',
                'gaps',
                'conflicts',
                'risks',
                'human_decision_required',
                'diagnostics',
            ],
            properties: [
                'schema_version' => [
                    'type' => 'integer',
                    'const' => PlanningExecutionResult::SCHEMA_VERSION,
                ],
                'outcome' => [
                    'type' => 'string',
                    'enum' => ['publishable', 'blocked'],
                ],
                'document_inventory' => $this->list(
                    $this->object(
                        required: [
                            'document_id',
                            'document_version_id',
                            'version',
                            'checksum_sha256',
                            'classification',
                            'summary',
                        ],
                        properties: [
                            ...$sourceReference['properties'],
                            'classification' => $this->text(),
                            'summary' => $this->text(),
                        ],
                    ),
                ),
                'document_summary' => $this->text(),
                'architecture_concerns' => $this->stringList(),
                'security_concerns' => $this->stringList(),
                'goal' => $this->text(),
                'scope' => $this->stringList(1),
                'assumptions' => $this->stringList(),
                'constraints' => $this->stringList(1),
                'definition_of_done' => $this->stringList(1),
                'required_approvals' => $this->list([
                    'type' => 'string',
                    'enum' => array_map(
                        static fn (ApprovalType $type): string => $type->value,
                        ApprovalType::cases(),
                    ),
                ], 1),
                'roadmap' => $this->object(
                    required: [
                        'phases',
                        'milestones',
                        'tasks',
                        'dependencies',
                    ],
                    properties: [
                        'phases' => $this->list($this->object(
                            required: ['stable_id', 'name'],
                            properties: [
                                'stable_id' => $this->stableId(),
                                'name' => $this->text(),
                            ],
                        )),
                        'milestones' => $this->list($this->object(
                            required: ['stable_id', 'name', 'phase_id'],
                            properties: [
                                'stable_id' => $this->stableId(),
                                'name' => $this->text(),
                                'phase_id' => $this->stableId(),
                            ],
                        )),
                        'tasks' => $this->list($task),
                        'dependencies' => $this->list($this->object(
                            required: ['task_id', 'depends_on_task_id'],
                            properties: [
                                'task_id' => $this->stableId(),
                                'depends_on_task_id' => $this->stableId(),
                            ],
                        )),
                    ],
                ),
                'gaps' => $this->stringList(),
                'conflicts' => $this->stringList(),
                'risks' => $this->stringList(),
                'human_decision_required' => ['type' => 'boolean'],
                'diagnostics' => $this->list($this->object(
                    required: ['code', 'category', 'message', 'details'],
                    properties: [
                        'code' => [
                            'type' => 'string',
                            'pattern' => '^[a-z][a-z0-9._-]{1,99}$',
                        ],
                        'category' => $this->text(),
                        'message' => $this->text(),
                        'details' => [
                            'type' => 'object',
                            'additionalProperties' => $diagnosticValue,
                        ],
                    ],
                )),
            ],
        );
    }

    /**
     * Build a strict JSON Schema object definition.
     *
     * @param  list<string>  $required
     * @param  array<string, array<string, mixed>>  $properties
     * @return array{
     *     type: 'object',
     *     additionalProperties: false,
     *     required: list<string>,
     *     properties: array<string, array<string, mixed>>
     * }
     */
    private function object(
        array $required,
        array $properties,
    ): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $required,
            'properties' => $properties,
        ];
    }

    /**
     * Build a bounded JSON Schema list definition.
     *
     * @param  array<string, mixed>  $items
     * @return array{
     *     type: 'array',
     *     minItems: int,
     *     maxItems: int,
     *     items: array<string, mixed>
     * }
     */
    private function list(
        array $items,
        int $minimum = 0,
    ): array {
        return [
            'type' => 'array',
            'minItems' => $minimum,
            'maxItems' => max(
                1,
                (int) config(
                    'codex-planning.maximum_output_list_items',
                    2_000,
                ),
            ),
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function stringList(int $minimum = 0): array
    {
        return $this->list($this->text(), $minimum);
    }

    /** @return array<string, mixed> */
    private function text(): array
    {
        return [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => $this->maximumStringBytes(),
        ];
    }

    /** @return array<string, mixed> */
    private function stableId(): array
    {
        return [
            'type' => 'string',
            'pattern' => '^[a-z][a-z0-9-]{1,99}$',
        ];
    }

    /** @return array<string, mixed> */
    private function positiveInteger(): array
    {
        return ['type' => 'integer', 'minimum' => 1];
    }

    /** @return array<string, mixed> */
    private function riskEnum(): array
    {
        return [
            'type' => 'string',
            'enum' => ['low', 'medium', 'high', 'critical'],
        ];
    }

    private function maximumStringBytes(): int
    {
        return max(
            1,
            (int) config('codex-planning.maximum_output_string_bytes', 32_768),
        );
    }
}
