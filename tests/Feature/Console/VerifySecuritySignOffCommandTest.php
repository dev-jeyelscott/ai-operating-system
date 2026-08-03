<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    File::deleteDirectory(aios150TemporaryDirectory());
    File::ensureDirectoryExists(aios150TemporaryDirectory());

    File::put(
        aios150EvidenceAbsolutePath(),
        "# AIOS-150 test evidence\n\nAutomated test fixture.\n",
    );
});

afterEach(function (): void {
    File::deleteDirectory(aios150TemporaryDirectory());
});

test('a complete approved security review passes', function (): void {
    aios150WriteManifest(aios150ValidManifest());

    $this->artisan('security:sign-off', [
        '--manifest' => aios150ManifestAbsolutePath(),
    ])
        ->expectsOutputToContain('Security sign-off passed.')
        ->assertSuccessful();
});

test('an unresolved critical finding blocks sign-off', function (): void {
    $manifest = aios150ValidManifest();

    $manifest['findings'] = [
        [
            'id' => 'SEC-001',
            'severity' => 'critical',
            'status' => 'open',
            'summary' => 'A Critical finding remains unresolved.',
            'owner' => 'Security owner',
            'due_date' => '2026-08-31',
            'evidence' => [aios150EvidenceRelativePath()],
        ],
    ];

    aios150WriteManifest($manifest);

    $this->artisan('security:sign-off', [
        '--manifest' => aios150ManifestAbsolutePath(),
    ])
        ->expectsOutputToContain('Security sign-off failed.')
        ->expectsOutputToContain(
            'Finding SEC-001 is an unresolved critical finding.',
        )
        ->assertFailed();
});

test('a missing hard dependency blocks sign-off', function (): void {
    $manifest = aios150ValidManifest();

    $manifest['dependencies'] = array_values(array_filter(
        $manifest['dependencies'],
        static fn (array $dependency): bool => $dependency['ticket_id'] !== 'AIOS-147',
    ));

    aios150WriteManifest($manifest);

    $this->artisan('security:sign-off', [
        '--manifest' => aios150ManifestAbsolutePath(),
    ])
        ->expectsOutputToContain('Required dependency AIOS-147 is missing.')
        ->assertFailed();
});

test('missing evidence blocks sign-off', function (): void {
    $manifest = aios150ValidManifest();
    $manifest['sign_off_evidence'] =
        'docs/evidence/non-existent-security-evidence.md';

    aios150WriteManifest($manifest);

    $this->artisan('security:sign-off', [
        '--manifest' => aios150ManifestAbsolutePath(),
    ])
        ->expectsOutputToContain(
            'sign_off_evidence does not reference an existing safe repository file',
        )
        ->assertFailed();
});

test('a named security reviewer and product owner are required', function (): void {
    $manifest = aios150ValidManifest();

    $manifest['reviewers'] = [
        [
            'role' => 'security_reviewer',
            'name' => 'Security reviewer',
        ],
    ];

    aios150WriteManifest($manifest);

    $this->artisan('security:sign-off', [
        '--manifest' => aios150ManifestAbsolutePath(),
    ])
        ->expectsOutputToContain(
            'A named product_owner approval is required.',
        )
        ->assertFailed();
});

/**
 * Return a complete valid security sign-off manifest.
 *
 * @return array<string, mixed>
 */
function aios150ValidManifest(): array
{
    $dependencies = array_map(
        static fn (string $ticketId): array => [
            'ticket_id' => $ticketId,
            'status' => 'passed',
            'evidence' => [aios150EvidenceRelativePath()],
        ],
        [
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
        ],
    );

    return [
        'schema_version' => 1,
        'ticket_id' => 'AIOS-150',
        'reviewed_at' => '2026-08-03T14:17:00+08:00',
        'reviewed_commit' => aios150CurrentCommit(),
        'decision' => 'approved',
        'reviewers' => [
            [
                'role' => 'security_reviewer',
                'name' => 'Security reviewer',
            ],
            [
                'role' => 'product_owner',
                'name' => 'Product owner',
            ],
        ],
        'sign_off_evidence' => aios150EvidenceRelativePath(),
        'dependencies' => $dependencies,
        'findings' => [],
    ];
}

/**
 * Write the supplied manifest to the isolated test directory.
 *
 * @param  array<string, mixed>  $manifest
 */
function aios150WriteManifest(array $manifest): void
{
    File::put(
        aios150ManifestAbsolutePath(),
        json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL,
    );
}

/**
 * Return the repository's current full Git commit.
 */
function aios150CurrentCommit(): string
{
    $process = new Process(
        ['git', 'rev-parse', 'HEAD'],
        base_path(),
    );

    $process->mustRun();

    return trim($process->getOutput());
}

/**
 * Return the temporary directory used by this test.
 */
function aios150TemporaryDirectory(): string
{
    return storage_path('framework/testing/aios-150');
}

/**
 * Return the absolute manifest fixture path.
 */
function aios150ManifestAbsolutePath(): string
{
    return aios150TemporaryDirectory().'/security-review.json';
}

/**
 * Return the absolute evidence fixture path.
 */
function aios150EvidenceAbsolutePath(): string
{
    return base_path(aios150EvidenceRelativePath());
}

/**
 * Return the repository-relative evidence fixture path.
 */
function aios150EvidenceRelativePath(): string
{
    return 'storage/framework/testing/aios-150/evidence.md';
}
