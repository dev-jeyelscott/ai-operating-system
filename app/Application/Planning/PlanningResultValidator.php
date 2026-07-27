<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Application\Planning\Data\PlanningAcceptanceCriterion;
use App\Application\Planning\Data\PlanningDependency;
use App\Application\Planning\Data\PlanningExecutionRequest;
use App\Application\Planning\Data\PlanningExecutionResult;
use App\Application\Planning\Data\PlanningMilestone;
use App\Application\Planning\Data\PlanningPhase;
use App\Application\Planning\Data\PlanningSourceReference;
use App\Application\Planning\Data\PlanningTask;
use App\Domain\Approvals\ApprovalType;
use InvalidArgumentException;

final class PlanningResultValidator
{
    /** @var list<string> */
    private const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    /** @var list<string> */
    private const RISKS = ['low', 'medium', 'high', 'critical'];

    /** @var list<string> */
    private const TICKET_TYPES = ['feature', 'bug', 'enhancement', 'change', 'technical_debt'];

    /** @var list<string> */
    private const LOGICAL_AGENTS = [
        'product_manager', 'business_analyst', 'project_manager', 'software_architect',
        'security_architect', 'database_architect', 'documentation_analyst',
        'backend_engineer', 'frontend_engineer', 'database_engineer', 'test_engineer',
        'devops_engineer',
    ];

    public function validate(PlanningExecutionResult $result, ?PlanningExecutionRequest $request = null): void
    {
        if ($result->schemaVersion !== PlanningExecutionResult::SCHEMA_VERSION) {
            throw new InvalidArgumentException('The planning result schema version is unsupported.');
        }
        if ($request !== null && $request->schemaVersion !== PlanningExecutionRequest::SCHEMA_VERSION) {
            throw new InvalidArgumentException('The planning request schema version is unsupported.');
        }
        if ($request !== null) {
            $this->validateRequest($request);
        }

        if (! in_array($result->outcome, ['publishable', 'blocked'], true)) {
            throw new InvalidArgumentException('The planning result outcome is invalid.');
        }

        $this->requiredString($result->documentSummary, 'document_summary');
        $this->requiredString($result->goal, 'goal');
        $this->stringList($result->architectureConcerns, 'architecture_concerns');
        $this->stringList($result->securityConcerns, 'security_concerns');
        $this->nonEmptyStringList($result->scope, 'scope');
        $this->stringList($result->assumptions, 'assumptions');
        $this->nonEmptyStringList($result->constraints, 'constraints');
        $this->nonEmptyStringList($result->definitionOfDone, 'definition_of_done');
        $this->nonEmptyStringList($result->requiredApprovals, 'required_approvals');
        $this->stringList($result->gaps, 'gaps');
        $this->stringList($result->conflicts, 'conflicts');
        $this->stringList($result->risks, 'risks');

        $approvalTypes = array_map(static fn (ApprovalType $type): string => $type->value, ApprovalType::cases());
        foreach ($result->requiredApprovals as $approval) {
            if (! in_array($approval, $approvalTypes, true)) {
                throw new InvalidArgumentException(sprintf('Unsupported required approval [%s].', $approval));
            }
        }

        $contextSources = $this->contextSources($request);
        $inventorySources = [];
        foreach ($result->documentInventory as $document) {
            $this->requiredString($document->classification, 'document_inventory.classification');
            $this->requiredString($document->summary, 'document_inventory.summary');
            $key = $this->validateSourceReference($document->source, $contextSources);
            if (isset($inventorySources[$key])) {
                throw new InvalidArgumentException('Document inventory entries must be unique.');
            }
            $inventorySources[$key] = true;
        }

        if ($request !== null && count($inventorySources) !== count($contextSources) && $result->outcome !== 'blocked') {
            throw new InvalidArgumentException('A publishable result must inventory every context document version.');
        }

        foreach ($result->diagnostics as $diagnostic) {
            $this->requiredString($diagnostic->code, 'diagnostic.code');
            $this->requiredString($diagnostic->category, 'diagnostic.category');
            $this->requiredString($diagnostic->message, 'diagnostic.message');
            if (preg_match('/\A[a-z][a-z0-9._-]{1,99}\z/', $diagnostic->code) !== 1) {
                throw new InvalidArgumentException('A planning diagnostic code is invalid.');
            }
            foreach ($diagnostic->details as $value) {
                if (! is_scalar($value) && $value !== null && ! $this->isScalarList($value)) {
                    throw new InvalidArgumentException('Planning diagnostic details must contain only scalars or scalar lists.');
                }
            }
        }

        if ($result->outcome === 'blocked' && $result->diagnostics === []) {
            throw new InvalidArgumentException('A blocked result requires a diagnostic.');
        }

        if ($result->outcome === 'publishable' && ($result->roadmap->phases === [] || $result->roadmap->tasks === [])) {
            throw new InvalidArgumentException('A publishable roadmap must include phases and tasks.');
        }

        if ($result->roadmap->tasks === []) {
            if ($result->roadmap->phases !== [] || $result->roadmap->milestones !== [] || $result->roadmap->dependencies !== []) {
                throw new InvalidArgumentException('A taskless blocked result cannot contain graph structure.');
            }

            return;
        }

        $phaseIds = $this->idSet();
        foreach ($result->roadmap->phases as $phase) {
            $this->validatePhase($phase, $phaseIds);
        }

        $milestoneIds = $this->milestoneMap();
        foreach ($result->roadmap->milestones as $milestone) {
            $this->validateMilestone($milestone, $phaseIds, $milestoneIds);
        }

        $taskIds = $this->idSet();
        $criterionIds = $this->idSet();
        foreach ($result->roadmap->tasks as $task) {
            $this->validateTask($task, $phaseIds, $milestoneIds, $contextSources, $taskIds, $criterionIds);
        }

        $dependencyIds = $this->idSet();
        foreach ($result->roadmap->dependencies as $dependency) {
            $this->validateDependency($dependency, $taskIds, $dependencyIds);
        }

        $allStableIds = $this->idSet();
        foreach ($result->roadmap->phases as $phase) {
            $this->uniqueId($phase->stableId, $allStableIds, 'roadmap stable');
        }
        foreach ($result->roadmap->milestones as $milestone) {
            $this->uniqueId($milestone->stableId, $allStableIds, 'roadmap stable');
        }
        foreach ($result->roadmap->tasks as $task) {
            $this->uniqueId($task->stableId, $allStableIds, 'roadmap stable');
            foreach ($task->acceptanceCriteria as $criterion) {
                $this->uniqueId($criterion->stableId, $allStableIds, 'roadmap stable');
            }
        }
    }

    /** @param array<string, true> $ids */
    private function validatePhase(PlanningPhase $phase, array &$ids): void
    {
        $this->stableId($phase->stableId, 'phase');
        $this->requiredString($phase->name, 'phase.name');
        $this->uniqueId($phase->stableId, $ids, 'phase');
    }

    /**
     * @param  array<string, true>  $phaseIds
     * @param  array<string, string>  $ids
     */
    private function validateMilestone(PlanningMilestone $milestone, array $phaseIds, array &$ids): void
    {
        $this->stableId($milestone->stableId, 'milestone');
        $this->requiredString($milestone->name, 'milestone.name');
        if (! isset($phaseIds[$milestone->phaseId])) {
            throw new InvalidArgumentException('A milestone references a missing phase.');
        }
        if (isset($ids[$milestone->stableId])) {
            throw new InvalidArgumentException('Milestone IDs must be unique.');
        }
        $ids[$milestone->stableId] = $milestone->phaseId;
    }

    /**
     * @param  array<string, true>  $phaseIds
     * @param  array<string, string>  $milestoneIds
     * @param  array<string, true>  $contextSources
     * @param  array<string, true>  $ids
     * @param  array<string, true>  $criterionIds
     */
    private function validateTask(PlanningTask $task, array $phaseIds, array $milestoneIds, array $contextSources, array &$ids, array &$criterionIds): void
    {
        $this->stableId($task->stableId, 'task');
        $this->requiredString($task->title, 'task.title');
        $this->requiredString($task->objective, 'task.objective');
        $this->requiredString($task->reasoning, 'task.reasoning');
        $this->uniqueId($task->stableId, $ids, 'task');

        if (! isset($phaseIds[$task->phaseId]) || ! isset($milestoneIds[$task->milestoneId])) {
            throw new InvalidArgumentException('A task references a missing phase or milestone.');
        }
        if ($milestoneIds[$task->milestoneId] !== $task->phaseId) {
            throw new InvalidArgumentException('A task milestone must belong to the task phase.');
        }
        if (! in_array($task->ticketType, self::TICKET_TYPES, true)) {
            throw new InvalidArgumentException('A task has an invalid ticket type.');
        }
        if (! in_array($task->priority, self::PRIORITIES, true)) {
            throw new InvalidArgumentException('A task has an invalid priority.');
        }
        if (! in_array($task->risk, self::RISKS, true)) {
            throw new InvalidArgumentException('A task has an invalid risk.');
        }
        if (! in_array($task->logicalAgent, self::LOGICAL_AGENTS, true)) {
            throw new InvalidArgumentException('A task has an invalid logical agent.');
        }
        if ($task->estimatedComplexity < 1 || $task->estimatedComplexity > 13) {
            throw new InvalidArgumentException('Task complexity must be between 1 and 13.');
        }
        $includedScope = $task->scope['included'] ?? null;
        $excludedScope = $task->scope['excluded'] ?? null;
        if (! is_array($includedScope) || ! is_array($excludedScope)) {
            throw new InvalidArgumentException('Task scope must define included and excluded lists.');
        }
        $this->nonEmptyStringList($includedScope, 'task.scope.included');
        $this->stringList($excludedScope, 'task.scope.excluded');
        $this->nonEmptyStringList($task->evidenceRequirements, 'task.evidence_requirements');

        if ($task->sourceReferences === [] || $task->acceptanceCriteria === []) {
            throw new InvalidArgumentException('Every task requires source and acceptance-criterion coverage.');
        }
        $taskSources = [];
        foreach ($task->sourceReferences as $sourceReference) {
            $sourceKey = $this->validateSourceReference($sourceReference, $contextSources);
            $this->uniqueId($sourceKey, $taskSources, 'task source reference');
        }

        foreach ($task->acceptanceCriteria as $criterion) {
            $this->validateCriterion($criterion, $contextSources, $criterionIds);
        }
    }

    /**
     * @param  array<string, true>  $contextSources
     * @param  array<string, true>  $ids
     */
    private function validateCriterion(PlanningAcceptanceCriterion $criterion, array $contextSources, array &$ids): void
    {
        $this->stableId($criterion->stableId, 'acceptance criterion');
        $this->requiredString($criterion->description, 'acceptance_criterion.description');
        $this->uniqueId($criterion->stableId, $ids, 'acceptance criterion');
        if ($criterion->sourceReferences === []) {
            throw new InvalidArgumentException('Every acceptance criterion requires source coverage.');
        }
        $criterionSources = [];
        foreach ($criterion->sourceReferences as $sourceReference) {
            $sourceKey = $this->validateSourceReference($sourceReference, $contextSources);
            $this->uniqueId($sourceKey, $criterionSources, 'acceptance-criterion source reference');
        }
    }

    /**
     * @param  array<string, true>  $taskIds
     * @param  array<string, true>  $ids
     */
    private function validateDependency(PlanningDependency $dependency, array $taskIds, array &$ids): void
    {
        if (! isset($taskIds[$dependency->taskId], $taskIds[$dependency->dependsOnTaskId])) {
            throw new InvalidArgumentException('A dependency references a missing task.');
        }
        if ($dependency->taskId === $dependency->dependsOnTaskId) {
            throw new InvalidArgumentException('A task cannot depend on itself.');
        }
        $key = $dependency->taskId.'|'.$dependency->dependsOnTaskId;
        $this->uniqueId($key, $ids, 'dependency');
    }

    /** @param array<string, true> $contextSources */
    private function validateSourceReference(PlanningSourceReference $reference, array $contextSources): string
    {
        if ($reference->documentId < 1 || $reference->documentVersionId < 1 || $reference->version < 1) {
            throw new InvalidArgumentException('Source reference identifiers and versions must be positive.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $reference->checksumSha256) !== 1) {
            throw new InvalidArgumentException('A source reference checksum is invalid.');
        }

        $key = $this->sourceKey($reference->documentId, $reference->documentVersionId, $reference->version, $reference->checksumSha256);
        if ($contextSources !== [] && ! isset($contextSources[$key])) {
            throw new InvalidArgumentException('A source reference is not part of the immutable planning context.');
        }

        return $key;
    }

    /** @return array<string, true> */
    private function contextSources(?PlanningExecutionRequest $request): array
    {
        $sources = [];
        if ($request === null) {
            return $sources;
        }
        foreach ($request->documents as $document) {
            $key = $this->validateSourceReference($document, []);
            if (isset($sources[$key])) {
                throw new InvalidArgumentException('Planning context document versions must be unique.');
            }
            $sources[$key] = true;
        }

        return $sources;
    }

    private function validateRequest(PlanningExecutionRequest $request): void
    {
        if ($request->projectId < 1 || $request->contextSnapshotId < 1) {
            throw new InvalidArgumentException('Planning request ownership identifiers must be positive.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $request->contextFingerprint) !== 1) {
            throw new InvalidArgumentException('The planning context fingerprint is invalid.');
        }
        if ($request->configurationVersionId !== null && $request->configurationVersionId < 1) {
            throw new InvalidArgumentException('The configuration version identifier is invalid.');
        }
        if ($request->configurationRevision !== null && $request->configurationRevision < 1) {
            throw new InvalidArgumentException('The configuration revision is invalid.');
        }
        if ($request->configurationSchemaVersion !== null && $request->configurationSchemaVersion < 1) {
            throw new InvalidArgumentException('The configuration schema version is invalid.');
        }
        if ($request->feedbackFingerprint !== null && preg_match('/\A[0-9a-f]{64}\z/', $request->feedbackFingerprint) !== 1) {
            throw new InvalidArgumentException('The regeneration feedback fingerprint is invalid.');
        }
    }

    private function sourceKey(int $documentId, int $documentVersionId, int $version, string $checksum): string
    {
        return implode(':', [$documentId, $documentVersionId, $version, $checksum]);
    }

    private function stableId(string $value, string $field): void
    {
        if (preg_match('/\A[a-z][a-z0-9-]{1,99}\z/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The %s stable ID is invalid.', $field));
        }
    }

    /** @param array<string, true> $ids */
    private function uniqueId(string $value, array &$ids, string $field): void
    {
        if (isset($ids[$value])) {
            throw new InvalidArgumentException(sprintf('%s IDs must be unique.', ucfirst($field)));
        }
        $ids[$value] = true;
    }

    private function requiredString(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Planning field [%s] is required.', $field));
        }
    }

    /** @param array<array-key, mixed> $values */
    private function stringList(array $values, string $field): void
    {
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Planning field [%s] must contain non-empty strings.', $field));
            }
        }
    }

    /** @param array<array-key, mixed> $values */
    private function nonEmptyStringList(array $values, string $field): void
    {
        if ($values === []) {
            throw new InvalidArgumentException(sprintf('Planning field [%s] cannot be empty.', $field));
        }
        $this->stringList($values, $field);
    }

    /** @return array<string, true> */
    private function idSet(): array
    {
        return [];
    }

    /** @return array<string, string> */
    private function milestoneMap(): array
    {
        return [];
    }

    private function isScalarList(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && array_all($value, static fn (mixed $item): bool => is_scalar($item));
    }
}
