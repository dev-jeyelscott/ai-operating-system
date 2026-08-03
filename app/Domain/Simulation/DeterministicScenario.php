<?php

declare(strict_types=1);

namespace App\Domain\Simulation;

/**
 * Defines the complete release-blocking deterministic MVP scenario catalog.
 */
enum DeterministicScenario: string
{
    case HappyPath = 'happy_path';
    case MissingRequiredDocument = 'missing_required_document';
    case ConflictingDocuments = 'conflicting_documents';
    case NotionTransientFailure = 'notion_transient_failure';
    case NoWorkableTicket = 'no_workable_ticket';
    case DependencyBlocked = 'dependency_blocked';
    case DevelopmentValidationFailure = 'development_validation_failure';
    case ProviderTimeout = 'provider_timeout';
    case WrongPullRequestTarget = 'wrong_pr_target';
    case QaChangesRequested = 'qa_changes_requested';
    case MergeReadyLowRisk = 'merge_ready_low_risk';
    case MergeReadyHighRisk = 'merge_ready_high_risk';
    case DuplicateStartProject = 'duplicate_start_project';
    case DuplicateNotionPublicationRetry = 'duplicate_notion_publication_retry';

    /**
     * Return every stable scenario slug in release-catalog order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $scenario): string => $scenario->value,
            self::cases(),
        );
    }

    /**
     * Return the immutable metadata and provider mappings for this scenario.
     *
     * @return array{
     *     title: string,
     *     category: string,
     *     description: string,
     *     expected_result: string,
     *     provider_scenarios: array{
     *         planning: string|null,
     *         development: string|null,
     *         quality_assurance: string|null
     *     },
     *     tags: list<string>
     * }
     */
    public function definition(): array
    {
        return match ($this) {
            self::HappyPath => [
                'title' => 'Happy path',
                'category' => 'end_to_end',
                'description' => 'Runs the complete simulated workflow through an authorized merge decision.',
                'expected_result' => 'The workflow reaches an authorized simulated merge decision without duplicate side effects.',
                'provider_scenarios' => [
                    'planning' => 'happy_path',
                    'development' => 'happy_path',
                    'quality_assurance' => 'merge_ready_low_risk',
                ],
                'tags' => ['release_blocking', 'happy_path', 'end_to_end'],
            ],
            self::MissingRequiredDocument => [
                'title' => 'Missing required document',
                'category' => 'planning',
                'description' => 'Blocks project start when one required document class is unavailable.',
                'expected_result' => 'Preflight blocks start and identifies the exact missing document class.',
                'provider_scenarios' => [
                    'planning' => 'missing_documents',
                    'development' => null,
                    'quality_assurance' => null,
                ],
                'tags' => ['release_blocking', 'planning', 'blocker'],
            ],
            self::ConflictingDocuments => [
                'title' => 'Conflicting documents',
                'category' => 'planning',
                'description' => 'Surfaces contradictory approved source-of-truth rules.',
                'expected_result' => 'Layer 1 records the conflict and requires a human decision before development.',
                'provider_scenarios' => [
                    'planning' => 'conflicts',
                    'development' => null,
                    'quality_assurance' => null,
                ],
                'tags' => ['release_blocking', 'planning', 'human_decision'],
            ],
            self::NotionTransientFailure => [
                'title' => 'Notion transient failure',
                'category' => 'notion_publication',
                'description' => 'Exercises retry and partial-publication recovery for a transient Notion failure.',
                'expected_result' => 'Retry publishes only missing tickets and preserves successful mappings.',
                'provider_scenarios' => [
                    'planning' => null,
                    'development' => null,
                    'quality_assurance' => null,
                ],
                'tags' => ['release_blocking', 'notion', 'retry', 'idempotency'],
            ],
            self::NoWorkableTicket => [
                'title' => 'No workable ticket',
                'category' => 'ticket_selection',
                'description' => 'Exercises a valid idle state when no ticket currently satisfies eligibility policy.',
                'expected_result' => 'The UI shows a no-workable-ticket state without marking the workflow failed.',
                'provider_scenarios' => [
                    'planning' => null,
                    'development' => null,
                    'quality_assurance' => null,
                ],
                'tags' => ['release_blocking', 'ticket_selection', 'idle'],
            ],
            self::DependencyBlocked => [
                'title' => 'Dependency blocked',
                'category' => 'ticket_selection',
                'description' => 'Keeps a ticket ineligible while a hard dependency is incomplete.',
                'expected_result' => 'The ticket remains unclaimed and exposes the blocking dependency explanation.',
                'provider_scenarios' => [
                    'planning' => null,
                    'development' => null,
                    'quality_assurance' => null,
                ],
                'tags' => ['release_blocking', 'ticket_selection', 'dependency'],
            ],
            self::DevelopmentValidationFailure => [
                'title' => 'Development validation failure',
                'category' => 'development',
                'description' => 'Produces a deterministic Layer 2 validation failure after simulated implementation.',
                'expected_result' => 'The ticket does not transition to For QA and the retry state remains visible.',
                'provider_scenarios' => [
                    'planning' => null,
                    'development' => 'development_validation_failure',
                    'quality_assurance' => null,
                ],
                'tags' => ['release_blocking', 'development', 'validation', 'retry'],
            ],
            self::ProviderTimeout => [
                'title' => 'Provider timeout',
                'category' => 'development',
                'description' => 'Produces a deterministic retryable provider timeout.',
                'expected_result' => 'Bounded retry occurs and terminal failure or recovery is observable.',
                'provider_scenarios' => [
                    'planning' => null,
                    'development' => 'provider_timeout_retry',
                    'quality_assurance' => null,
                ],
                'tags' => ['release_blocking', 'development', 'timeout', 'retry'],
            ],
            self::WrongPullRequestTarget => [
                'title' => 'Wrong pull request target',
                'category' => 'development',
                'description' => 'Produces a synthetic pull request that incorrectly targets main.',
                'expected_result' => 'Deterministic policy rejects main and records the violation without advancing the ticket.',
                'provider_scenarios' => [
                    'planning' => null,
                    'development' => 'wrong_pr_target',
                    'quality_assurance' => null,
                ],
                'tags' => ['release_blocking', 'development', 'branch_policy', 'security'],
            ],
            self::QaChangesRequested => [
                'title' => 'QA changes requested',
                'category' => 'quality_assurance',
                'description' => 'Produces an independent QA result with a blocking functional finding.',
                'expected_result' => 'The ticket enters the changes-requested loop and preserves prior execution history.',
                'provider_scenarios' => [
                    'planning' => null,
                    'development' => null,
                    'quality_assurance' => 'changes_requested',
                ],
                'tags' => ['release_blocking', 'quality_assurance', 'changes_requested'],
            ],
            self::MergeReadyLowRisk => [
                'title' => 'Merge ready, low risk',
                'category' => 'quality_assurance',
                'description' => 'Produces a low-residual-risk simulated QA recommendation.',
                'expected_result' => 'An authorized user may approve the simulated merge decision while evidence stays unverified.',
                'provider_scenarios' => [
                    'planning' => null,
                    'development' => null,
                    'quality_assurance' => 'merge_ready_low_risk',
                ],
                'tags' => ['release_blocking', 'quality_assurance', 'low_risk'],
            ],
            self::MergeReadyHighRisk => [
                'title' => 'Merge ready, high risk',
                'category' => 'quality_assurance',
                'description' => 'Produces a merge-ready-with-risks result with consequential operational impact.',
                'expected_result' => 'Explicit human review or escalation is required before disposition.',
                'provider_scenarios' => [
                    'planning' => null,
                    'development' => null,
                    'quality_assurance' => 'merge_ready_high_risk',
                ],
                'tags' => ['release_blocking', 'quality_assurance', 'high_risk', 'human_review'],
            ],
            self::DuplicateStartProject => [
                'title' => 'Duplicate Start Project',
                'category' => 'start_project',
                'description' => 'Replays StartProject with the same project context and idempotency key.',
                'expected_result' => 'The original execution is returned without another snapshot, workflow, or roadmap.',
                'provider_scenarios' => [
                    'planning' => null,
                    'development' => null,
                    'quality_assurance' => null,
                ],
                'tags' => ['release_blocking', 'start_project', 'idempotency', 'replay'],
            ],
            self::DuplicateNotionPublicationRetry => [
                'title' => 'Duplicate Notion publication retry',
                'category' => 'notion_publication',
                'description' => 'Replays publication after mappings already exist.',
                'expected_result' => 'Existing pages are updated or skipped and no duplicate Notion page is created.',
                'provider_scenarios' => [
                    'planning' => null,
                    'development' => null,
                    'quality_assurance' => null,
                ],
                'tags' => ['release_blocking', 'notion', 'idempotency', 'replay'],
            ],
        };
    }
}
