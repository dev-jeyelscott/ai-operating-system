<?php

declare(strict_types=1);

namespace App\Application\Development;

use App\Application\Development\Data\DevelopmentExecutionRequest;
use App\Application\Development\Data\DevelopmentExecutionResult;
use App\Application\Development\Data\DevelopmentRepositoryArtifact;
use App\Domain\Development\DevelopmentExecutionOutcome;
use App\Domain\Development\DevelopmentStage;
use App\Domain\Development\DevelopmentStageStatus;
use App\Domain\Development\DevelopmentValidationStatus;
use Illuminate\Support\Str;

final class DevelopmentResultValidator
{
    public function validateRequest(DevelopmentExecutionRequest $request): void
    {
        if ($request->schemaVersion !== DevelopmentExecutionRequest::SCHEMA_VERSION) {
            throw new \InvalidArgumentException('Development request schema version is unsupported.');
        }

        foreach ([$request->organizationId, $request->projectId, $request->roadmapId, $request->ticketId, $request->attemptNumber, $request->contextSnapshotId] as $identifier) {
            if ($identifier < 1) {
                throw new \InvalidArgumentException('Development request identifiers must be positive.');
            }
        }

        foreach ([$request->executionId, $request->attemptId, $request->leaseId] as $identifier) {
            if (! Str::isUlid($identifier)) {
                throw new \InvalidArgumentException('Development request ULID is invalid.');
            }
        }

        if (preg_match('/\A[0-9a-f]{64}\z/', $request->contextFingerprint) !== 1) {
            throw new \InvalidArgumentException('Development context fingerprint is invalid.');
        }

        $this->text($request->ticketObjective, 'ticket objective', 10_000);
        $this->stringList($request->includedScope, 'included scope');
        $this->stringList($request->excludedScope, 'excluded scope');
        $this->stringList($request->acceptanceCriteria, 'acceptance criteria');
        $this->stringList($request->dependencyReferences, 'dependency references');
        $this->stringList($request->evidenceRequirements, 'evidence requirements');
        $this->stringList($request->validationCommands, 'validation commands');

        if ($request->integrationTarget !== 'develop') {
            throw new \InvalidArgumentException('Development integration target must be develop.');
        }

        if ($request->complexity < 1 || $request->complexity > 13) {
            throw new \InvalidArgumentException('Development complexity is invalid.');
        }

        $this->text($request->repositoryBaseReference, 'repository base reference', 500);
        $this->rejectSecrets($request->toArray());
        $this->boundedPayload($request->toArray());
    }

    public function validateResult(DevelopmentExecutionResult $result): void
    {
        if ($result->schemaVersion !== DevelopmentExecutionResult::SCHEMA_VERSION) {
            throw new \InvalidArgumentException('Development result schema version is unsupported.');
        }

        $this->text($result->providerIdentifier, 'provider identifier', 100);

        if ($result->capability !== 'development.simulation') {
            throw new \InvalidArgumentException('Development result capability is invalid.');
        }

        if ($result->confidence < 0 || $result->confidence > 1 || ! is_finite($result->confidence)) {
            throw new \InvalidArgumentException('Development result confidence is invalid.');
        }

        $expectedStages = array_map(static fn (DevelopmentStage $stage): string => $stage->value, DevelopmentStage::cases());
        $actualStages = array_map(static fn ($stage): string => $stage->stage->value, $result->stageResults);

        if ($actualStages !== $expectedStages) {
            throw new \InvalidArgumentException('Development stages are missing or out of order.');
        }

        $paths = [];

        foreach ($result->changedFiles as $file) {
            $this->validatePath($file->path);

            if (isset($paths[$file->path])) {
                throw new \InvalidArgumentException('Development changed-file paths must be unique.');
            }

            $paths[$file->path] = true;
            $this->text($file->summary, 'changed-file summary', 2_000);
        }

        foreach ($result->validationResults as $validation) {
            $this->text($validation->command, 'validation command', 1_000);
            $this->text($validation->summary, 'validation summary', 4_000);
        }

        $artifacts = array_filter([
            $result->syntheticBranchResult, $result->syntheticCommitResult,
            $result->syntheticPushResult, $result->syntheticPullRequestResult,
        ]);

        foreach ($artifacts as $artifact) {
            $this->validateArtifact($artifact);
        }

        if ($result->targetBranch !== 'develop') {
            throw new \InvalidArgumentException('Synthetic pull request target must be develop.');
        }

        if ($result->evidenceGaps === []) {
            throw new \InvalidArgumentException('Simulated development results require evidence gaps.');
        }

        if ($result->outcome === DevelopmentExecutionOutcome::Succeeded) {
            if (count($artifacts) !== 4
                || array_any($result->validationResults, static fn ($validation): bool => $validation->status !== DevelopmentValidationStatus::Passed)
                || array_any($result->stageResults, static fn ($stage): bool => $stage->status !== DevelopmentStageStatus::Passed)) {
                throw new \InvalidArgumentException('Successful development result contradicts failed or missing stages.');
            }
        }

        $this->stringList($result->implementationPlan, 'implementation plan');
        $this->stringList($result->assumptions, 'assumptions');
        $this->stringList($result->risks, 'risks');
        $this->stringList($result->evidenceGaps, 'evidence gaps');
        $this->rejectSecrets($result->toArray(false));
        $this->boundedPayload($result->toArray(false));

        if (! hash_equals($this->fingerprint($result), $result->canonicalResultFingerprint)) {
            throw new \InvalidArgumentException('Development result fingerprint does not match canonical content.');
        }
    }

    public function fingerprint(DevelopmentExecutionResult $result): string
    {
        return hash('sha256', $this->canonicalJson($result->toArray(false)));
    }

    public function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function validateCanonicalPayload(string $payload): void
    {
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if ($payload !== $this->canonicalJson($decoded)) {
            throw new \InvalidArgumentException('Development payload serialization is not canonical.');
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = array_map($this->canonicalize(...), $value);
        if (! array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }

    private function validatePath(string $path): void
    {
        if ($path === '' || strlen($path) > 255 || str_starts_with($path, '/') || str_contains($path, '\\') || preg_match('/(^|\/)\.\.?(\/|$)/', $path) === 1 || preg_match('/\A[A-Za-z]:/', $path) === 1) {
            throw new \InvalidArgumentException('Development changed-file path must be normalized and project-relative.');
        }
    }

    private function validateArtifact(DevelopmentRepositoryArtifact $artifact): void
    {
        if (! $artifact->synthetic || ! $artifact->evidenceStillRequired || ! str_starts_with($artifact->reference, 'simulation://')) {
            throw new \InvalidArgumentException('Repository artifacts must remain simulated and unverified.');
        }

        if (preg_match('/(?:https?:\/\/|git@github\.com)/i', $artifact->reference) === 1) {
            throw new \InvalidArgumentException('Real repository references are prohibited in simulation.');
        }

        if ($artifact->kind === 'pull_request' && $artifact->targetBranch !== 'develop') {
            throw new \InvalidArgumentException('Synthetic pull request target must be develop.');
        }
    }

    /** @param list<string> $values */
    private function stringList(array $values, string $name): void
    {
        if (count($values) > 100) {
            throw new \InvalidArgumentException("Development {$name} exceeds the item limit.");
        }

        foreach ($values as $value) {
            $this->text($value, $name, 2_000);
        }
    }

    private function text(string $value, string $name, int $maximum): void
    {
        if ($value === '' || trim($value) !== $value || strlen($value) > $maximum) {
            throw new \InvalidArgumentException("Development {$name} is invalid.");
        }
    }

    private function rejectSecrets(mixed $value): void
    {
        $serialized = json_encode($value, JSON_THROW_ON_ERROR);
        if (preg_match('/(?:sk-[A-Za-z0-9]{12,}|gh[pousr]_[A-Za-z0-9]{12,}|password\s*[=:]|api[_-]?key\s*[=:]|private[_-]?key)/i', $serialized) === 1) {
            throw new \InvalidArgumentException('Development contract contains an unredacted secret.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function boundedPayload(array $payload): void
    {
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 262_144) {
            throw new \InvalidArgumentException('Development contract exceeds the maximum payload size.');
        }
    }
}
