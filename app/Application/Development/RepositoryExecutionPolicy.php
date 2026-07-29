<?php

declare(strict_types=1);

namespace App\Application\Development;

use App\Application\Development\Data\RepositoryPolicyDecision;
use App\Domain\Development\RepositoryPolicyFailureReason;
use App\Domain\Projects\Configuration\GitBranchName;
use Illuminate\Support\Str;

final class RepositoryExecutionPolicy
{
    private const string TARGET = 'develop';

    /** @var array<string, string> */
    private const array PREFIXES = [
        'feature' => 'feature', 'bug' => 'fix', 'enhancement' => 'enhancement',
        'change' => 'change', 'security' => 'chore', 'documentation' => 'chore',
        'infrastructure' => 'chore', 'investigation' => 'chore', 'technical debt' => 'chore',
    ];

    /** @var list<string> */
    private const array PROTECTED = ['main', 'master', 'develop'];

    public function sourceBranch(string $ticketType, string $stableTicketId, string $ticketTitle, ?string $approvedPrefix = null): string
    {
        $type = strtolower(trim($ticketType));
        $prefix = $approvedPrefix ?? self::PREFIXES[$type] ?? null;

        if ($prefix === null || ! preg_match('/\A[a-z][a-z0-9-]*\z/', $prefix)) {
            throw new \InvalidArgumentException(RepositoryPolicyFailureReason::InvalidTicketType->value);
        }

        $stableId = Str::slug(Str::ascii($stableTicketId));
        $title = Str::slug(Str::ascii($ticketTitle));

        if ($stableId === '' || $title === '') {
            throw new \InvalidArgumentException(RepositoryPolicyFailureReason::InvalidSourceBranch->value);
        }

        $maximumSlugLength = 120 - strlen($prefix) - strlen($stableId) - 2;
        $title = rtrim(substr($title, 0, max(1, $maximumSlugLength)), '-');
        $branch = "{$prefix}/{$stableId}-{$title}";

        if (! GitBranchName::isValid($branch) || in_array($branch, self::PROTECTED, true)) {
            throw new \InvalidArgumentException(RepositoryPolicyFailureReason::InvalidSourceBranch->value);
        }

        return $branch;
    }

    public function validatePullRequest(string $sourceBranch, string $targetBranch): RepositoryPolicyDecision
    {
        if ($targetBranch === '' || trim($targetBranch) === '') {
            return RepositoryPolicyDecision::rejected(RepositoryPolicyFailureReason::BlankTarget);
        }

        if (! GitBranchName::isValid($sourceBranch)) {
            return RepositoryPolicyDecision::rejected(RepositoryPolicyFailureReason::InvalidSourceBranch);
        }

        if ($sourceBranch === $targetBranch) {
            return RepositoryPolicyDecision::rejected(RepositoryPolicyFailureReason::SourceEqualsTarget);
        }

        if (in_array($sourceBranch, self::PROTECTED, true)) {
            return RepositoryPolicyDecision::rejected(RepositoryPolicyFailureReason::ProtectedSource);
        }

        if ($targetBranch === 'main' || $targetBranch === 'master') {
            return RepositoryPolicyDecision::rejected(RepositoryPolicyFailureReason::MainTarget);
        }

        if ($targetBranch !== self::TARGET) {
            return RepositoryPolicyDecision::rejected(
                in_array($targetBranch, self::PROTECTED, true)
                    ? RepositoryPolicyFailureReason::ProtectedTarget
                    : RepositoryPolicyFailureReason::UnapprovedTarget,
            );
        }

        return RepositoryPolicyDecision::allowed();
    }

    public function validateDirectPush(string $branch): RepositoryPolicyDecision
    {
        if (in_array($branch, self::PROTECTED, true)) {
            return RepositoryPolicyDecision::rejected(RepositoryPolicyFailureReason::DirectPushProtected);
        }

        return GitBranchName::isValid($branch)
            ? RepositoryPolicyDecision::allowed()
            : RepositoryPolicyDecision::rejected(RepositoryPolicyFailureReason::InvalidSourceBranch);
    }
}
