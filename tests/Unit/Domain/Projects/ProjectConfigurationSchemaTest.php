<?php

declare(strict_types=1);

use App\Domain\Projects\Configuration\Exceptions\UnsupportedProjectConfigurationSchemaVersion;
use App\Domain\Projects\Configuration\ProjectConfigurationSchema;

test('the current project configuration schema is supported', function (): void {
    expect(
        ProjectConfigurationSchema::supports(
            ProjectConfigurationSchema::CURRENT_VERSION,
        ),
    )->toBeTrue();
});

test('an unknown project configuration schema is rejected', function (): void {
    expect(
        fn () => ProjectConfigurationSchema::assertSupported(999),
    )->toThrow(UnsupportedProjectConfigurationSchemaVersion::class);
});

test('schema defaults use conservative project policies', function (): void {
    expect(ProjectConfigurationSchema::approvalPolicyDefaults())
        ->toBe([
            'roadmap_required' => true,
            'ticket_execution_required' => true,
            'merge_required' => true,
        ])
        ->and(ProjectConfigurationSchema::notificationPolicyDefaults())
        ->toBe([
            'channels' => ['in_app'],
            'events' => [],
        ]);
});
