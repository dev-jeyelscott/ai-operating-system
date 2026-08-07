<?php

declare(strict_types=1);

use App\Models\Approval;
use App\Models\Execution;
use App\Models\NotificationEvent;
use App\Models\NotificationRecipient;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CompletedQualityAssuranceFixture;
use Tests\Support\TicketTestFixture;

uses(RefreshDatabase::class);

/**
 * Create one actionable recipient-scoped notification.
 *
 * @param  array<string, mixed>  $action
 */
function createActionableNotification(
    Project $project,
    User $user,
    array $action,
): NotificationRecipient {
    $event = NotificationEvent::factory()
        ->forProject($project)
        ->create([
            'data' => [
                'requires_action' => true,
                'action' => $action,
            ],
        ]);

    return NotificationRecipient::factory()
        ->forEvent($event)
        ->forRecipient($user)
        ->create();
}

it('opens an execution notification in the development inspector', function (): void {
    $fixture = TicketTestFixture::create(
        stableId: 'AIOS-116-EXECUTION',
    );

    $project = $fixture['project'];
    $user = CompletedQualityAssuranceFixture::user(
        $project->organization_id,
    );

    $execution = Execution::factory()
        ->for($project)
        ->create([
            'capability' => 'development.simulation',
            'logical_role' => 'backend_engineer',
        ]);

    $recipient = createActionableNotification(
        project: $project,
        user: $user,
        action: [
            'type' => 'execution',
            'execution_id' => $execution->id,
        ],
    );

    $response = $this
        ->actingAs($user)
        ->post(route(
            'organizations.notifications.open',
            [
                'organization' => $project->organization,
                'notificationRecipient' => $recipient->id,
            ],
        ));

    $response
        ->assertStatus(303)
        ->assertRedirect(route(
            'organizations.projects.development.executions.show',
            [
                'organization' => $project->organization,
                'project' => $project,
                'execution' => $execution,
            ],
            false,
        ));

    expect($recipient->refresh()->read_at)->not->toBeNull();
});

it('opens a blocker notification on the affected ticket', function (): void {
    $fixture = TicketTestFixture::create(
        stableId: 'AIOS-116-BLOCKER',
    );

    $project = $fixture['project'];
    $ticket = $fixture['ticket'];
    $user = CompletedQualityAssuranceFixture::user(
        $project->organization_id,
    );

    $recipient = createActionableNotification(
        project: $project,
        user: $user,
        action: [
            'type' => 'blocker',
            'ticket_id' => $ticket->stable_id,
        ],
    );

    $expected = route(
        'organizations.projects.roadmaps.tasks.show',
        [
            'organization' => $project->organization,
            'project' => $project,
            'roadmap' => $fixture['roadmap'],
            'task' => $ticket,
        ],
        false,
    ).'#blocker';

    $this
        ->actingAs($user)
        ->post(route(
            'organizations.notifications.open',
            [
                'organization' => $project->organization,
                'notificationRecipient' => $recipient->id,
            ],
        ))
        ->assertStatus(303)
        ->assertRedirect($expected);
});

it('opens a roadmap approval notification on its roadmap', function (): void {
    $fixture = TicketTestFixture::create(
        stableId: 'AIOS-116-APPROVAL',
    );

    $project = $fixture['project'];
    $roadmap = $fixture['roadmap'];
    $user = CompletedQualityAssuranceFixture::user(
        $project->organization_id,
    );

    $approval = Approval::factory()
        ->for($project)
        ->create([
            'request_payload' => [
                'roadmap_id' => $roadmap->id,
            ],
        ]);

    $recipient = createActionableNotification(
        project: $project,
        user: $user,
        action: [
            'type' => 'approval',
            'approval_id' => $approval->id,
            'roadmap_id' => $roadmap->id,
        ],
    );

    $expected = route(
        'organizations.projects.roadmaps.show',
        [
            'organization' => $project->organization,
            'project' => $project,
            'roadmap' => $roadmap,
        ],
        false,
    ).'#approval';

    $this
        ->actingAs($user)
        ->post(route(
            'organizations.notifications.open',
            [
                'organization' => $project->organization,
                'notificationRecipient' => $recipient->id,
            ],
        ))
        ->assertStatus(303)
        ->assertRedirect($expected);
});

it('opens a decision notification in the merge decision center', function (): void {
    $fixture = CompletedQualityAssuranceFixture::create();

    $project = $fixture['project'];
    $assessment = $fixture['assessment'];
    $user = CompletedQualityAssuranceFixture::user(
        $project->organization_id,
    );

    $recipient = createActionableNotification(
        project: $project,
        user: $user,
        action: [
            'type' => 'decision',
            'qa_assessment_id' => $assessment->id,
        ],
    );

    $expected = route(
        'organizations.projects.quality-assurance.index',
        [
            'organization' => $project->organization,
            'project' => $project,
            'assessment' => $assessment->id,
        ],
        false,
    ).'#decision-center';

    $this
        ->actingAs($user)
        ->post(route(
            'organizations.notifications.open',
            [
                'organization' => $project->organization,
                'notificationRecipient' => $recipient->id,
            ],
        ))
        ->assertStatus(303)
        ->assertRedirect($expected);
});

it('conceals another users actionable notification', function (): void {
    $fixture = TicketTestFixture::create(
        stableId: 'AIOS-116-ISOLATION',
    );

    $project = $fixture['project'];

    $currentUser = CompletedQualityAssuranceFixture::user(
        $project->organization_id,
    );

    $otherUser = CompletedQualityAssuranceFixture::user(
        $project->organization_id,
    );

    $recipient = createActionableNotification(
        project: $project,
        user: $otherUser,
        action: [
            'type' => 'blocker',
            'ticket_id' => $fixture['ticket']->stable_id,
        ],
    );

    $this
        ->actingAs($currentUser)
        ->post(route(
            'organizations.notifications.open',
            [
                'organization' => $project->organization,
                'notificationRecipient' => $recipient->id,
            ],
        ))
        ->assertNotFound();

    expect($recipient->refresh()->read_at)->toBeNull();
});
