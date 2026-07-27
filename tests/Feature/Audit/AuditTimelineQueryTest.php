<?php

declare(strict_types=1);

use App\Application\Audit\Data\AuditTimelineCriteria;
use App\Application\Audit\Data\AuditTimelineOrder;
use App\Application\Audit\ListAuditTimeline;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Persist one audit event fixture with an explicit authoritative timestamp.
 *
 * @param  array<string, mixed>  $overrides
 */
function createAuditTimelineEvent(
    int $organizationId,
    ?int $projectId,
    CarbonImmutable $occurredAt,
    array $overrides = [],
): AuditEvent {
    return AuditEvent::query()->create(array_merge([
        'event_id' => (string) Str::ulid(),
        'organization_id' => $organizationId,
        'project_id' => $projectId,
        'actor_type' => AuditActorType::System,
        'actor_id' => 'audit-timeline-test',
        'event_type' => AuditEventType::ProjectCreated,
        'subject_type' => AuditSubjectType::Project,
        'subject_id' => (string) ($projectId ?? $organizationId),
        'correlation_id' => null,
        'causation_id' => null,
        'execution_id' => null,
        'schema_version' => 1,
        'deduplication_key' => null,
        'metadata' => [],
        'occurred_at' => $occurredAt,
    ], $overrides));
}

test('audit timeline ordering is stable by sequence then timestamp', function () {
    $organization = Organization::factory()->create();

    $project = Project::factory()
        ->for($organization)
        ->create();

    /*
     * Intentionally store timestamps out of chronological order. Sequence
     * remains the authoritative ordering key.
     */
    $first = createAuditTimelineEvent(
        organizationId: $organization->id,
        projectId: $project->id,
        occurredAt: CarbonImmutable::parse(
            '2026-07-27T12:03:00+08:00',
        ),
    );

    $second = createAuditTimelineEvent(
        organizationId: $organization->id,
        projectId: $project->id,
        occurredAt: CarbonImmutable::parse(
            '2026-07-27T12:01:00+08:00',
        ),
    );

    $third = createAuditTimelineEvent(
        organizationId: $organization->id,
        projectId: $project->id,
        occurredAt: CarbonImmutable::parse(
            '2026-07-27T12:02:00+08:00',
        ),
    );

    $oldestFirst = app(ListAuditTimeline::class)->handle(
        new AuditTimelineCriteria(
            organizationId: $organization->id,
            projectId: $project->id,
            order: AuditTimelineOrder::OldestFirst,
        ),
    );

    $newestFirst = app(ListAuditTimeline::class)->handle(
        new AuditTimelineCriteria(
            organizationId: $organization->id,
            projectId: $project->id,
            order: AuditTimelineOrder::NewestFirst,
        ),
    );

    expect(
        collect($oldestFirst->items())
            ->pluck('sequence')
            ->all(),
    )->toBe([
        $first->sequence,
        $second->sequence,
        $third->sequence,
    ]);

    expect(
        collect($newestFirst->items())
            ->pluck('sequence')
            ->all(),
    )->toBe([
        $third->sequence,
        $second->sequence,
        $first->sequence,
    ]);
});

test('audit timeline filters remain tenant and project scoped', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();

    $project = Project::factory()
        ->for($organization)
        ->create();

    $otherProject = Project::factory()
        ->for($otherOrganization)
        ->create();

    $matching = createAuditTimelineEvent(
        organizationId: $organization->id,
        projectId: $project->id,
        occurredAt: CarbonImmutable::parse(
            '2026-07-27T13:00:00+08:00',
        ),
        overrides: [
            'event_type' => AuditEventType::ExecutionAttemptStarted,
            'subject_type' => AuditSubjectType::Execution,
            'subject_id' => 'execution-001',
            'execution_id' => 'execution-001',
            'correlation_id' => 'correlation-001',
            'causation_id' => 'causation-001',
        ],
    );

    createAuditTimelineEvent(
        organizationId: $organization->id,
        projectId: $project->id,
        occurredAt: CarbonImmutable::parse(
            '2026-07-27T13:01:00+08:00',
        ),
        overrides: [
            'event_type' => AuditEventType::ExecutionAttemptStarted,
            'subject_type' => AuditSubjectType::Execution,
            'subject_id' => 'execution-002',
            'execution_id' => 'execution-002',
            'correlation_id' => 'correlation-002',
            'causation_id' => 'causation-002',
        ],
    );

    /*
     * This event deliberately reuses the same trace identifiers in another
     * tenant. It must never be returned for the selected organization.
     */
    createAuditTimelineEvent(
        organizationId: $otherOrganization->id,
        projectId: $otherProject->id,
        occurredAt: CarbonImmutable::parse(
            '2026-07-27T13:02:00+08:00',
        ),
        overrides: [
            'event_type' => AuditEventType::ExecutionAttemptStarted,
            'subject_type' => AuditSubjectType::Execution,
            'subject_id' => 'execution-001',
            'execution_id' => 'execution-001',
            'correlation_id' => 'correlation-001',
            'causation_id' => 'causation-001',
        ],
    );

    $timeline = app(ListAuditTimeline::class)->handle(
        new AuditTimelineCriteria(
            organizationId: $organization->id,
            projectId: $project->id,
            executionId: 'execution-001',
            correlationId: 'correlation-001',
            causationId: 'causation-001',
            eventType: AuditEventType::ExecutionAttemptStarted,
            subjectType: AuditSubjectType::Execution,
            subjectId: 'execution-001',
        ),
    );

    expect($timeline->items())
        ->toHaveCount(1);

    expect($timeline->items()[0]->event_id)
        ->toBe($matching->event_id);

    expect($timeline->items()[0]->organization_id)
        ->toBe($organization->id);
});

test('cursor pagination remains stable when newer events are appended', function () {
    $organization = Organization::factory()->create();

    $project = Project::factory()
        ->for($organization)
        ->create();

    $events = collect(range(1, 5))->map(
        fn (int $minute): AuditEvent => createAuditTimelineEvent(
            organizationId: $organization->id,
            projectId: $project->id,
            occurredAt: CarbonImmutable::parse(sprintf(
                '2026-07-27T14:%02d:00+08:00',
                $minute,
            )),
        ),
    );

    $firstPage = app(ListAuditTimeline::class)->handle(
        new AuditTimelineCriteria(
            organizationId: $organization->id,
            projectId: $project->id,
            order: AuditTimelineOrder::OldestFirst,
            perPage: 2,
        ),
    );

    $nextCursor = $firstPage->nextCursor();

    expect($nextCursor)
        ->not
        ->toBeNull();

    /*
     * Append another event after the client has received the first page.
     * Existing page boundaries must not shift or duplicate earlier rows.
     */
    $appended = createAuditTimelineEvent(
        organizationId: $organization->id,
        projectId: $project->id,
        occurredAt: CarbonImmutable::parse(
            '2026-07-27T14:06:00+08:00',
        ),
    );

    $secondPage = app(ListAuditTimeline::class)->handle(
        new AuditTimelineCriteria(
            organizationId: $organization->id,
            projectId: $project->id,
            order: AuditTimelineOrder::OldestFirst,
            perPage: 2,
            cursor: $nextCursor?->encode(),
        ),
    );

    $thirdPage = app(ListAuditTimeline::class)->handle(
        new AuditTimelineCriteria(
            organizationId: $organization->id,
            projectId: $project->id,
            order: AuditTimelineOrder::OldestFirst,
            perPage: 2,
            cursor: $secondPage->nextCursor()?->encode(),
        ),
    );

    expect(
        collect($firstPage->items())
            ->pluck('sequence')
            ->all(),
    )->toBe([
        $events[0]->sequence,
        $events[1]->sequence,
    ]);

    expect(
        collect($secondPage->items())
            ->pluck('sequence')
            ->all(),
    )->toBe([
        $events[2]->sequence,
        $events[3]->sequence,
    ]);

    expect(
        collect($thirdPage->items())
            ->pluck('sequence')
            ->all(),
    )->toBe([
        $events[4]->sequence,
        $appended->sequence,
    ]);
});

test('audit timeline criteria reject unsafe query boundaries', function () {
    expect(
        fn () => new AuditTimelineCriteria(
            organizationId: 0,
        ),
    )->toThrow(InvalidArgumentException::class);

    expect(
        fn () => new AuditTimelineCriteria(
            organizationId: 1,
            perPage: AuditTimelineCriteria::MAX_PER_PAGE + 1,
        ),
    )->toThrow(InvalidArgumentException::class);

    expect(
        fn () => new AuditTimelineCriteria(
            organizationId: 1,
            subjectId: 'execution-001',
        ),
    )->toThrow(InvalidArgumentException::class);
});
