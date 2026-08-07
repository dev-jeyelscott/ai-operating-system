<?php

declare(strict_types=1);

use App\Domain\Identity\OrganizationRole;
use App\Domain\Tickets\TicketStatus;
use App\Infrastructure\QualityAssurance\QaScenarioCatalog;
use App\Models\MergeDecision;
use App\Models\Organization;
use App\Models\QaAssessment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\CompletedQualityAssuranceFixture;

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, string>
 */
function aios112DecisionPayload(
    array $fixture,
    string $action,
    ?string $reason = null,
    ?string $idempotencyKey = null,
): array {
    /** @var QaAssessment $assessment */
    $assessment = $fixture['assessment'];

    return array_filter([
        'action' => $action,
        'expected_assessment_fingerprint' => $assessment
            ->canonical_assessment_fingerprint,
        'idempotency_key' => $idempotencyKey
            ?? sprintf('merge-decision:%s:%s', $assessment->id, Str::uuid()),
        'reason' => $reason,
    ], static fn (?string $value): bool => $value !== null);
}

/**
 * @param  array<string, mixed>  $fixture
 */
function aios112DecisionUrl(array $fixture): string
{
    return route(
        'organizations.projects.quality-assurance.decisions.store',
        [
            'organization' => $fixture['project']->organization,
            'project' => $fixture['project'],
            'assessment' => $fixture['assessment'],
        ],
    );
}

test('an owner can approve a low-risk simulated assessment', function (): void {
    Http::fake();

    $fixture = CompletedQualityAssuranceFixture::create();
    $owner = CompletedQualityAssuranceFixture::user(
        $fixture['project']->organization_id,
    );

    $this->actingAs($owner)
        ->post(aios112DecisionUrl($fixture), aios112DecisionPayload(
            fixture: $fixture,
            action: 'approve',
        ))
        ->assertRedirect()
        ->assertSessionHas('status', 'simulated-merge-approved')
        ->assertSessionHasNoErrors();

    expect($fixture['ticket']->refresh()->status)
        ->toBe(TicketStatus::ApprovedForMerge);

    $decision = MergeDecision::query()->sole();

    expect($decision->action->value)->toBe('approve')
        ->and($decision->simulated)->toBeTrue()
        ->and($decision->actual_state)->toBe('unverified')
        ->and($decision->terminal_marker)->toBe('T');

    Http::assertNothingSent();
});

test('request changes requires a reason and re-enters the ticket safely', function (): void {
    $fixture = CompletedQualityAssuranceFixture::create();
    $owner = CompletedQualityAssuranceFixture::user(
        $fixture['project']->organization_id,
    );
    $url = aios112DecisionUrl($fixture);

    $this->actingAs($owner)
        ->post($url, aios112DecisionPayload($fixture, 'request_changes'))
        ->assertRedirect()
        ->assertSessionHasErrors('reason');

    $this->assertDatabaseCount('merge_decisions', 0);

    $this->actingAs($owner)
        ->post($url, aios112DecisionPayload(
            fixture: $fixture,
            action: 'request_changes',
            reason: 'Add rollback evidence before another review.',
        ))
        ->assertRedirect()
        ->assertSessionHas('status', 'simulated-merge-changes-requested');

    expect($fixture['ticket']->refresh()->status)
        ->toBe(TicketStatus::ChangesRequested);
});

test('escalation is nonterminal and a later decision remains possible', function (): void {
    $fixture = CompletedQualityAssuranceFixture::create(
        QaScenarioCatalog::MERGE_READY_HIGH_RISK,
    );
    $owner = CompletedQualityAssuranceFixture::user(
        $fixture['project']->organization_id,
    );
    $url = aios112DecisionUrl($fixture);

    $this->actingAs($owner)
        ->post($url, aios112DecisionPayload(
            fixture: $fixture,
            action: 'escalate',
            reason: 'Rollback complexity requires release-owner review.',
        ))
        ->assertRedirect()
        ->assertSessionHas('status', 'simulated-merge-escalated');

    expect($fixture['ticket']->refresh()->status)->toBe(TicketStatus::ForQa)
        ->and(MergeDecision::query()->sole()->terminal_marker)->toBeNull();

    $this->actingAs($owner)
        ->post($url, aios112DecisionPayload(
            fixture: $fixture,
            action: 'defer',
            reason: 'Wait for the release window.',
        ))
        ->assertRedirect()
        ->assertSessionHas('status', 'simulated-merge-deferred');

    $this->assertDatabaseCount('merge_decisions', 2);
});

test('escalation requires a reason', function (): void {
    $fixture = CompletedQualityAssuranceFixture::create(
        QaScenarioCatalog::MERGE_READY_HIGH_RISK,
    );
    $owner = CompletedQualityAssuranceFixture::user(
        $fixture['project']->organization_id,
    );

    $this->actingAs($owner)
        ->post(aios112DecisionUrl($fixture), aios112DecisionPayload(
            fixture: $fixture,
            action: 'escalate',
        ))
        ->assertRedirect()
        ->assertSessionHasErrors('reason');

    expect($fixture['ticket']->refresh()->status)->toBe(TicketStatus::ForQa);
    $this->assertDatabaseCount('merge_decisions', 0);
});

test('deferring leaves the ticket in For QA', function (): void {
    $fixture = CompletedQualityAssuranceFixture::create();
    $owner = CompletedQualityAssuranceFixture::user(
        $fixture['project']->organization_id,
    );

    $this->actingAs($owner)
        ->post(aios112DecisionUrl($fixture), aios112DecisionPayload(
            fixture: $fixture,
            action: 'defer',
            reason: 'Wait for the release window.',
        ))
        ->assertRedirect()
        ->assertSessionHas('status', 'simulated-merge-deferred')
        ->assertSessionHasNoErrors();

    expect($fixture['ticket']->refresh()->status)->toBe(TicketStatus::ForQa)
        ->and(MergeDecision::query()->sole()->terminal_marker)->toBeNull();
});

test('a terminal decision rejects another terminal decision', function (): void {
    $fixture = CompletedQualityAssuranceFixture::create();
    $owner = CompletedQualityAssuranceFixture::user(
        $fixture['project']->organization_id,
    );
    $url = aios112DecisionUrl($fixture);

    $this->actingAs($owner)
        ->post($url, aios112DecisionPayload($fixture, 'approve'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->post($url, aios112DecisionPayload(
            fixture: $fixture,
            action: 'request_changes',
            reason: 'Attempt to replace the terminal decision.',
        ))
        ->assertRedirect()
        ->assertSessionHasErrors('decision');

    expect($fixture['ticket']->refresh()->status)
        ->toBe(TicketStatus::ApprovedForMerge);
    $this->assertDatabaseCount('merge_decisions', 1);
});

test('a stale assessment fingerprint is rejected safely', function (): void {
    $fixture = CompletedQualityAssuranceFixture::create();
    $owner = CompletedQualityAssuranceFixture::user(
        $fixture['project']->organization_id,
    );
    $payload = aios112DecisionPayload($fixture, 'approve');
    $payload['expected_assessment_fingerprint'] = str_repeat('0', 64);

    $this->actingAs($owner)
        ->post(aios112DecisionUrl($fixture), $payload)
        ->assertRedirect()
        ->assertSessionHasErrors('decision');

    expect($fixture['ticket']->refresh()->status)->toBe(TicketStatus::ForQa);
    $this->assertDatabaseCount('merge_decisions', 0);
});

test('a non-develop target branch is rejected safely', function (): void {
    $fixture = CompletedQualityAssuranceFixture::create();
    $owner = CompletedQualityAssuranceFixture::user(
        $fixture['project']->organization_id,
    );
    QaAssessment::query()
        ->whereKey($fixture['assessment']->id)
        ->update(['target_branch' => 'main']);
    $fixture['assessment']->refresh();

    $this->actingAs($owner)
        ->post(aios112DecisionUrl($fixture), aios112DecisionPayload(
            fixture: $fixture,
            action: 'approve',
        ))
        ->assertRedirect()
        ->assertSessionHasErrors('decision');

    expect($fixture['ticket']->refresh()->status)->toBe(TicketStatus::ForQa);
    $this->assertDatabaseCount('merge_decisions', 0);
});

test('an exact duplicate request creates only one decision', function (): void {
    $fixture = CompletedQualityAssuranceFixture::create();
    $owner = CompletedQualityAssuranceFixture::user(
        $fixture['project']->organization_id,
    );
    $payload = aios112DecisionPayload(
        fixture: $fixture,
        action: 'approve',
        idempotencyKey: sprintf('merge-decision:%s:duplicate', $fixture['assessment']->id),
    );

    $this->actingAs($owner)->post(aios112DecisionUrl($fixture), $payload)
        ->assertRedirect();
    $this->actingAs($owner)->post(aios112DecisionUrl($fixture), $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->assertDatabaseCount('merge_decisions', 1);
});

test('reusing an idempotency key with changed input returns a safe conflict', function (): void {
    $fixture = CompletedQualityAssuranceFixture::create();
    $owner = CompletedQualityAssuranceFixture::user(
        $fixture['project']->organization_id,
    );
    $key = sprintf('merge-decision:%s:conflict', $fixture['assessment']->id);
    $url = aios112DecisionUrl($fixture);

    $this->actingAs($owner)->post($url, aios112DecisionPayload(
        fixture: $fixture,
        action: 'defer',
        idempotencyKey: $key,
    ))->assertRedirect();

    $this->actingAs($owner)->post($url, aios112DecisionPayload(
        fixture: $fixture,
        action: 'escalate',
        reason: 'Different input.',
        idempotencyKey: $key,
    ))
        ->assertRedirect()
        ->assertSessionHasErrors('decision');

    $this->assertDatabaseCount('merge_decisions', 1);
});

test('blocking findings prevent approval', function (): void {
    $fixture = CompletedQualityAssuranceFixture::create(
        QaScenarioCatalog::BLOCKED,
    );
    $owner = CompletedQualityAssuranceFixture::user(
        $fixture['project']->organization_id,
    );

    $this->actingAs($owner)
        ->post(aios112DecisionUrl($fixture), aios112DecisionPayload(
            fixture: $fixture,
            action: 'approve',
        ))
        ->assertRedirect()
        ->assertSessionHasErrors('decision');

    expect($fixture['ticket']->refresh()->status)->toBe(TicketStatus::ForQa);
    $this->assertDatabaseCount('merge_decisions', 0);
});

test('a member without approval permission is forbidden', function (): void {
    $fixture = CompletedQualityAssuranceFixture::create();
    $member = CompletedQualityAssuranceFixture::user(
        $fixture['project']->organization_id,
        OrganizationRole::Member,
    );

    $this->actingAs($member)
        ->post(aios112DecisionUrl($fixture), aios112DecisionPayload(
            fixture: $fixture,
            action: 'approve',
        ))
        ->assertForbidden();

    $this->assertDatabaseCount('merge_decisions', 0);
});

test('a cross-organization assessment URL is not discoverable', function (): void {
    $fixture = CompletedQualityAssuranceFixture::create();
    $otherOrganization = Organization::factory()->create();
    $outsider = CompletedQualityAssuranceFixture::user($otherOrganization->id);
    $url = route(
        'organizations.projects.quality-assurance.decisions.store',
        [
            'organization' => $otherOrganization,
            'project' => $fixture['project'],
            'assessment' => $fixture['assessment'],
        ],
    );

    $this->actingAs($outsider)
        ->post($url, aios112DecisionPayload($fixture, 'approve'))
        ->assertNotFound();

    $this->assertDatabaseCount('merge_decisions', 0);
});
