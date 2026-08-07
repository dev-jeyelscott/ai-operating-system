<?php

declare(strict_types=1);

use App\Domain\Evidence\EvidenceClassification;
use App\Models\Evidence;
use Carbon\CarbonImmutable;

function evidenceAtState(
    EvidenceClassification $classification,
    ?CarbonImmutable $verifiedAt = null,
    ?CarbonImmutable $expiresAt = null,
    string $provider = 'github',
): Evidence {
    $evidence = new Evidence;
    $evidence->forceFill([
        'classification' => $classification,
        'provider' => $provider,
        'verified_at' => $verifiedAt,
        'expires_at' => $expiresAt,
    ]);

    return $evidence;
}

it('treats verified evidence without an expiry as current', function () {
    $asOf = CarbonImmutable::parse('2026-07-30T09:00:00Z');
    $evidence = evidenceAtState(
        EvidenceClassification::VerifiedEvidence,
        verifiedAt: $asOf->subMinute(),
    );

    expect($evidence->isCurrentlyVerifiedAt($asOf))->toBeTrue()
        ->and($evidence->displayStateAt($asOf))->toBe('verified');
});

it('treats verified evidence with a future expiry as current', function () {
    $asOf = CarbonImmutable::parse('2026-07-30T09:00:00Z');
    $evidence = evidenceAtState(
        EvidenceClassification::VerifiedEvidence,
        verifiedAt: $asOf->subMinute(),
        expiresAt: $asOf->addSecond(),
    );

    expect($evidence->isCurrentlyVerifiedAt($asOf))->toBeTrue()
        ->and($evidence->displayStateAt($asOf))->toBe('verified');
});

it('treats verified evidence expiring at or before the report time as stale', function (CarbonImmutable $expiresAt) {
    $asOf = CarbonImmutable::parse('2026-07-30T09:00:00Z');
    $evidence = evidenceAtState(
        EvidenceClassification::VerifiedEvidence,
        verifiedAt: $asOf->subHour(),
        expiresAt: $expiresAt,
    );

    expect($evidence->isExpiredAt($asOf))->toBeTrue()
        ->and($evidence->isCurrentlyVerifiedAt($asOf))->toBeFalse()
        ->and($evidence->displayStateAt($asOf))->toBe('stale');
})->with([
    'exact boundary' => CarbonImmutable::parse('2026-07-30T09:00:00Z'),
    'past expiry' => CarbonImmutable::parse('2026-07-30T08:59:59Z'),
]);

it('does not verify a classification without a verification timestamp', function () {
    $asOf = CarbonImmutable::parse('2026-07-30T09:00:00Z');
    $evidence = evidenceAtState(EvidenceClassification::VerifiedEvidence);

    expect($evidence->isCurrentlyVerifiedAt($asOf))->toBeFalse()
        ->and($evidence->displayStateAt($asOf))->toBe('unverified');
});

it('never treats simulated provider output as current verified evidence', function () {
    $asOf = CarbonImmutable::parse('2026-07-30T09:00:00Z');
    $evidence = evidenceAtState(
        EvidenceClassification::VerifiedEvidence,
        verifiedAt: $asOf->subMinute(),
        provider: 'simulation',
    );

    expect($evidence->isCurrentlyVerifiedAt($asOf))->toBeFalse()
        ->and($evidence->displayStateAt($asOf))->toBe('simulated');
});

it('never treats rejected evidence as verified', function () {
    $asOf = CarbonImmutable::parse('2026-07-30T09:00:00Z');
    $evidence = evidenceAtState(
        EvidenceClassification::RejectedEvidence,
        verifiedAt: $asOf->subMinute(),
    );

    expect($evidence->isCurrentlyVerifiedAt($asOf))->toBeFalse()
        ->and($evidence->displayStateAt($asOf))->toBe('rejected');
});
