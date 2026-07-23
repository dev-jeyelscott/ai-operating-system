<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectSetupStep;

test('project setup steps have a deterministic workflow order', function (): void {
    expect(ProjectSetupStep::ordered())
        ->toBe([
            ProjectSetupStep::Details,
            ProjectSetupStep::Repository,
            ProjectSetupStep::Integrations,
            ProjectSetupStep::Commands,
            ProjectSetupStep::Policies,
            ProjectSetupStep::Review,
        ]);
});

test('each project setup step resolves its canonical successor', function (): void {
    expect(ProjectSetupStep::Details->next())
        ->toBe(ProjectSetupStep::Repository)
        ->and(ProjectSetupStep::Repository->next())
        ->toBe(ProjectSetupStep::Integrations)
        ->and(ProjectSetupStep::Integrations->next())
        ->toBe(ProjectSetupStep::Commands)
        ->and(ProjectSetupStep::Commands->next())
        ->toBe(ProjectSetupStep::Policies)
        ->and(ProjectSetupStep::Policies->next())
        ->toBe(ProjectSetupStep::Review)
        ->and(ProjectSetupStep::Review->next())
        ->toBeNull();
});
