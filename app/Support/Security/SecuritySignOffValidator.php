<?php

declare(strict_types=1);

namespace App\Support\Security;

use DateTimeImmutable;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Throwable;

/**
 * Validates the machine-readable AIOS-150 security review manifest.
 */
final readonly class SecuritySignOffValidator
{
    /**
     * Every hard dependency that must be evidenced before AIOS-150 can pass.
     *
     * @var list<string>
     */
    private const REQUIRED_DEPENDENCIES = [
        'AIOS-010',
        'AIOS-137',
        'AIOS-138',
        'AIOS-139',
        'AIOS-140',
        'AIOS-141',
        'AIOS-142',
        'AIOS-143',
        'AIOS-144',
        'AIOS-145',
        'AIOS-146',
        'AIOS-147',
        'AIOS-148',
        'AIOS-149',
    ];

    /**
     * Finding severities supported by the manifest contract.
     *
     * @var list<string>
     */
    private const VALID_SEVERITIES = [
        'critical',
        'high',
        'medium',
        'low',
        'info',
    ];

    /**
     * Finding lifecycle values supported by the manifest contract.
     *
     * @var list<string>
     */
    private const VALID_FINDING_STATUSES = [
        'open',
        'mitigated',
        'accepted',
        'false_positive',
    ];

    /**
     * Statuses that close a Critical or High finding.
     *
     * @var list<string>
     */
    private const CLOSED_BLOCKING_FINDING_STATUSES = [
        'mitigated',
        'false_positive',
    ];

    /**
     * Inject the filesystem used to read the manifest and evidence.
     */
    public function __construct(
        private Filesystem $files,
    ) {}

    /**
     * Validate the complete security review manifest.
     */
    public function validate(string $manifestPath): SecuritySignOffResult
    {
        if (! $this->files->isFile($manifestPath)) {
            return new SecuritySignOffResult(
                decision: 'missing',
                reviewedCommit: '',
                dependencyCount: 0,
                findingCount: 0,
                violations: [
                    sprintf(
                        'Security review manifest does not exist: %s',
                        $manifestPath,
                    ),
                ],
            );
        }

        try {
            $decoded = json_decode(
                $this->files->get($manifestPath),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            return new SecuritySignOffResult(
                decision: 'invalid',
                reviewedCommit: '',
                dependencyCount: 0,
                findingCount: 0,
                violations: [
                    sprintf(
                        'Security review manifest contains invalid JSON: %s',
                        $exception->getMessage(),
                    ),
                ],
            );
        }

        if (! is_array($decoded)) {
            return new SecuritySignOffResult(
                decision: 'invalid',
                reviewedCommit: '',
                dependencyCount: 0,
                findingCount: 0,
                violations: [
                    'Security review manifest must contain a JSON object.',
                ],
            );
        }

        /** @var array<string, mixed> $manifest */
        $manifest = $decoded;
        $violations = [];

        $decision = strtolower($this->string($manifest['decision'] ?? null));
        $reviewedCommit = strtolower(
            $this->string($manifest['reviewed_commit'] ?? null),
        );

        if (($manifest['schema_version'] ?? null) !== 1) {
            $violations[] = 'schema_version must be the integer 1.';
        }

        if ($this->string($manifest['ticket_id'] ?? null) !== 'AIOS-150') {
            $violations[] = 'ticket_id must be AIOS-150.';
        }

        if ($decision !== 'approved') {
            $violations[] = 'decision must be approved before the gate can pass.';
        }

        if (! $this->isIsoDateTime(
            $this->string($manifest['reviewed_at'] ?? null),
        )) {
            $violations[] = 'reviewed_at must be an ISO-8601 timestamp.';
        }

        if (preg_match('/\A[0-9a-f]{40}\z/', $reviewedCommit) !== 1) {
            $violations[] = 'reviewed_commit must be a full 40-character Git SHA.';
        }

        $this->validateReviewers(
            $manifest['reviewers'] ?? null,
            $violations,
        );

        $this->validateEvidencePaths(
            $manifest['sign_off_evidence'] ?? null,
            'sign_off_evidence',
            $violations,
        );

        $dependencyCount = $this->validateDependencies(
            $manifest['dependencies'] ?? null,
            $violations,
        );

        $findingCount = $this->validateFindings(
            $manifest['findings'] ?? null,
            $violations,
        );

        return new SecuritySignOffResult(
            decision: $decision,
            reviewedCommit: $reviewedCommit,
            dependencyCount: $dependencyCount,
            findingCount: $findingCount,
            violations: array_values(array_unique($violations)),
        );
    }

    /**
     * Require the human roles needed for final approval.
     *
     * @param  list<string>  $violations
     */
    private function validateReviewers(
        mixed $reviewers,
        array &$violations,
    ): void {
        if (! is_array($reviewers) || $reviewers === []) {
            $violations[] = 'reviewers must contain human approval records.';

            return;
        }

        /** @var array<string, true> $approvedRoles */
        $approvedRoles = [];

        foreach ($reviewers as $index => $reviewer) {
            if (! is_array($reviewer)) {
                $violations[] = sprintf(
                    'reviewers.%d must be an object.',
                    $index,
                );

                continue;
            }

            $role = strtolower($this->string($reviewer['role'] ?? null));
            $name = $this->string($reviewer['name'] ?? null);

            if ($role === '') {
                $violations[] = sprintf(
                    'reviewers.%d.role is required.',
                    $index,
                );
            }

            if ($name === '') {
                $violations[] = sprintf(
                    'reviewers.%d.name is required.',
                    $index,
                );
            } elseif ($this->isPlaceholderReviewerName($name)) {
                $violations[] = sprintf(
                    'reviewers.%d.name must identify an actual reviewer.',
                    $index,
                );
            }

            if ($role !== '' && $name !== '') {
                $approvedRoles[$role] = true;
            }
        }

        foreach (['security_reviewer', 'product_owner'] as $requiredRole) {
            if (! isset($approvedRoles[$requiredRole])) {
                $violations[] = sprintf(
                    'A named %s approval is required.',
                    $requiredRole,
                );
            }
        }
    }

    /**
     * Reject instructional reviewer placeholders that are not human identities.
     */
    private function isPlaceholderReviewerName(string $name): bool
    {
        return preg_match(
            '/\b(actual|insert|replace|your)\b.*\b(name|reviewer|owner)\b/i',
            $name,
        ) === 1;
    }

    /**
     * Validate every hard dependency and its evidence references.
     *
     * @param  list<string>  $violations
     */
    private function validateDependencies(
        mixed $dependencies,
        array &$violations,
    ): int {
        if (! is_array($dependencies)) {
            $violations[] = 'dependencies must be an array.';

            return 0;
        }

        /** @var array<string, true> $observedDependencies */
        $observedDependencies = [];

        foreach ($dependencies as $index => $dependency) {
            if (! is_array($dependency)) {
                $violations[] = sprintf(
                    'dependencies.%d must be an object.',
                    $index,
                );

                continue;
            }

            $ticketId = $this->string($dependency['ticket_id'] ?? null);
            $status = strtolower($this->string($dependency['status'] ?? null));

            if ($ticketId === '') {
                $violations[] = sprintf(
                    'dependencies.%d.ticket_id is required.',
                    $index,
                );

                continue;
            }

            if (isset($observedDependencies[$ticketId])) {
                $violations[] = sprintf(
                    'Dependency %s is duplicated.',
                    $ticketId,
                );
            }

            $observedDependencies[$ticketId] = true;

            if ($status !== 'passed') {
                $violations[] = sprintf(
                    'Dependency %s must have status passed.',
                    $ticketId,
                );
            }

            $this->validateEvidencePaths(
                $dependency['evidence'] ?? null,
                sprintf('Dependency %s evidence', $ticketId),
                $violations,
            );
        }

        foreach (self::REQUIRED_DEPENDENCIES as $requiredDependency) {
            if (! isset($observedDependencies[$requiredDependency])) {
                $violations[] = sprintf(
                    'Required dependency %s is missing.',
                    $requiredDependency,
                );
            }
        }

        return count($dependencies);
    }

    /**
     * Validate findings and enforce the Critical and High release gate.
     *
     * @param  list<string>  $violations
     */
    private function validateFindings(
        mixed $findings,
        array &$violations,
    ): int {
        if (! is_array($findings)) {
            $violations[] = 'findings must be an array.';

            return 0;
        }

        /** @var array<string, true> $findingIds */
        $findingIds = [];

        foreach ($findings as $index => $finding) {
            if (! is_array($finding)) {
                $violations[] = sprintf(
                    'findings.%d must be an object.',
                    $index,
                );

                continue;
            }

            $id = $this->string($finding['id'] ?? null);
            $severity = strtolower(
                $this->string($finding['severity'] ?? null),
            );
            $status = strtolower($this->string($finding['status'] ?? null));
            $summary = $this->string($finding['summary'] ?? null);

            if ($id === '') {
                $violations[] = sprintf(
                    'findings.%d.id is required.',
                    $index,
                );
            } elseif (isset($findingIds[$id])) {
                $violations[] = sprintf('Finding %s is duplicated.', $id);
            } else {
                $findingIds[$id] = true;
            }

            if (! in_array($severity, self::VALID_SEVERITIES, true)) {
                $violations[] = sprintf(
                    'Finding %s has an invalid severity.',
                    $id !== '' ? $id : (string) $index,
                );
            }

            if (! in_array($status, self::VALID_FINDING_STATUSES, true)) {
                $violations[] = sprintf(
                    'Finding %s has an invalid status.',
                    $id !== '' ? $id : (string) $index,
                );
            }

            if ($summary === '') {
                $violations[] = sprintf(
                    'Finding %s requires a summary.',
                    $id !== '' ? $id : (string) $index,
                );
            }

            $this->validateEvidencePaths(
                $finding['evidence'] ?? null,
                sprintf(
                    'Finding %s evidence',
                    $id !== '' ? $id : (string) $index,
                ),
                $violations,
            );

            if (
                in_array($severity, ['critical', 'high'], true)
                && ! in_array(
                    $status,
                    self::CLOSED_BLOCKING_FINDING_STATUSES,
                    true,
                )
            ) {
                $violations[] = sprintf(
                    'Finding %s is an unresolved %s finding.',
                    $id !== '' ? $id : (string) $index,
                    $severity,
                );
            }

            if (in_array($status, ['open', 'accepted'], true)) {
                if ($this->string($finding['owner'] ?? null) === '') {
                    $violations[] = sprintf(
                        'Finding %s requires an owner.',
                        $id !== '' ? $id : (string) $index,
                    );
                }

                if (! $this->isIsoDate(
                    $this->string($finding['due_date'] ?? null),
                )) {
                    $violations[] = sprintf(
                        'Finding %s requires a valid due_date.',
                        $id !== '' ? $id : (string) $index,
                    );
                }
            }
        }

        return count($findings);
    }

    /**
     * Validate one or more repository-relative evidence paths.
     *
     * @param  list<string>  $violations
     */
    private function validateEvidencePaths(
        mixed $evidence,
        string $context,
        array &$violations,
    ): void {
        $paths = is_string($evidence)
            ? [$evidence]
            : $evidence;

        if (! is_array($paths) || $paths === []) {
            $violations[] = sprintf('%s must not be empty.', $context);

            return;
        }

        foreach ($paths as $path) {
            $normalizedPath = $this->string($path);

            if ($normalizedPath === '') {
                $violations[] = sprintf(
                    '%s contains an empty path.',
                    $context,
                );

                continue;
            }

            if (! $this->isSafeRepositoryFile($normalizedPath)) {
                $violations[] = sprintf(
                    '%s does not reference an existing safe repository file: %s',
                    $context,
                    $normalizedPath,
                );
            }
        }
    }

    /**
     * Determine whether an evidence path remains inside the repository.
     */
    private function isSafeRepositoryFile(string $path): bool
    {
        $normalizedPath = str_replace('\\', '/', trim($path));

        if (
            str_starts_with($normalizedPath, '/')
            || preg_match('/\A[A-Za-z]:\//', $normalizedPath) === 1
            || in_array('..', explode('/', $normalizedPath), true)
        ) {
            return false;
        }

        $repositoryRoot = realpath(base_path());
        $evidencePath = realpath(base_path($normalizedPath));

        if (
            ! is_string($repositoryRoot)
            || ! is_string($evidencePath)
            || ! is_file($evidencePath)
        ) {
            return false;
        }

        return $evidencePath === $repositoryRoot
            || str_starts_with(
                $evidencePath,
                $repositoryRoot.DIRECTORY_SEPARATOR,
            );
    }

    /**
     * Determine whether a value is a supported ISO-8601 timestamp.
     */
    private function isIsoDateTime(string $value): bool
    {
        if (
            preg_match(
                '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}'
                .'(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/',
                $value,
            ) !== 1
        ) {
            return false;
        }

        try {
            new DateTimeImmutable($value);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether a value is a valid calendar date.
     */
    private function isIsoDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false
            && $date->format('Y-m-d') === $value;
    }

    /**
     * Return a trimmed string or an empty string for another value type.
     */
    private function string(mixed $value): string
    {
        return is_string($value)
            ? trim($value)
            : '';
    }
}
