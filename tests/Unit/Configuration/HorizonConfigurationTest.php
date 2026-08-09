<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

test('every Horizon environment supervisor has complete default configuration', function (): void {
    $defaults = config('horizon.defaults');
    $environments = config('horizon.environments');

    Assert::assertIsArray(
        $defaults,
        'Horizon defaults configuration must be an array.',
    );

    Assert::assertIsArray(
        $environments,
        'Horizon environments configuration must be an array.',
    );

    foreach ($environments as $environment => $supervisors) {
        Assert::assertIsArray(
            $supervisors,
            sprintf(
                'Horizon environment [%s] must contain supervisor definitions.',
                (string) $environment,
            ),
        );

        foreach ($supervisors as $supervisorName => $overrides) {
            Assert::assertIsString($supervisorName);

            Assert::assertIsArray(
                $overrides,
                sprintf(
                    'Horizon supervisor [%s] overrides must be an array.',
                    $supervisorName,
                ),
            );

            Assert::assertArrayHasKey(
                $supervisorName,
                $defaults,
                sprintf(
                    'Horizon supervisor [%s] in environment [%s] has no matching defaults configuration.',
                    $supervisorName,
                    (string) $environment,
                ),
            );

            $supervisorDefaults = $defaults[$supervisorName];

            Assert::assertIsArray(
                $supervisorDefaults,
                sprintf(
                    'Horizon defaults for supervisor [%s] must be an array.',
                    $supervisorName,
                ),
            );

            $resolved = array_replace(
                $supervisorDefaults,
                $overrides,
            );

            foreach ([
                'connection',
                'queue',
                'balance',
                'autoScalingStrategy',
                'maxProcesses',
                'maxTime',
                'maxJobs',
                'memory',
                'tries',
                'timeout',
                'nice',
            ] as $requiredKey) {
                Assert::assertArrayHasKey(
                    $requiredKey,
                    $resolved,
                    sprintf(
                        'Resolved Horizon supervisor [%s] for environment [%s] is missing required key [%s].',
                        $supervisorName,
                        (string) $environment,
                        $requiredKey,
                    ),
                );
            }

            Assert::assertSame(
                'redis',
                $resolved['connection'],
                sprintf(
                    'Horizon supervisor [%s] must use the Redis queue connection.',
                    $supervisorName,
                ),
            );

            $queue = $resolved['queue'];

            Assert::assertIsArray(
                $queue,
                sprintf(
                    'Horizon supervisor [%s] queue configuration must be an array.',
                    $supervisorName,
                ),
            );

            Assert::assertNotEmpty(
                $queue,
                sprintf(
                    'Horizon supervisor [%s] must listen to at least one queue.',
                    $supervisorName,
                ),
            );
        }
    }
});
