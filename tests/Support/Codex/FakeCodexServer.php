<?php

declare(strict_types=1);

namespace Tests\Support\Codex;

use App\Application\Codex\Data\CodexProcessContext;
use App\Infrastructure\Codex\Process\CodexAppServerSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

final class FakeCodexServer
{
    public const string BINARY_VERSION = '0.0.0-aios-fake';

    public const string SCHEMA_FINGERPRINT =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * Configure every Laravel test to use the repository-owned fake executable.
     */
    public static function configureDefaults(): void
    {
        putenv('AIOS_CODEX_FAKE_SERVER=1');

        $allowedEnvironment = config(
            'codex-app-server.allowed_environment',
            [],
        );

        if (! is_array($allowedEnvironment)) {
            $allowedEnvironment = [];
        }

        $allowedEnvironment = array_values(array_unique([
            ...array_filter(
                $allowedEnvironment,
                static fn (mixed $value): bool => is_string($value),
            ),
            'PATH',
            'AIOS_CODEX_FAKE_SERVER',
        ]));

        config()->set([
            'codex-app-server.executable' => self::executablePath(),
            'codex-app-server.arguments' => [
                'app-server',
                '--listen',
                'stdio://',
                '--catalog',
                self::catalogPath(),
                '--scenario',
                'success',
            ],
            'codex-app-server.version_arguments' => [
                '--version',
            ],
            'codex-app-server.expected_binary_version' => self::BINARY_VERSION,
            'codex-app-server.protocol_version' => 'stable',
            'codex-app-server.schema_fingerprint' => self::SCHEMA_FINGERPRINT,
            'codex-app-server.allowed_environment' => $allowedEnvironment,
        ]);

        self::forgetSettings();
    }

    /**
     * Configure one deterministic scenario and return its request-record path.
     *
     * @param  array<string, mixed>  $configurationOverrides
     */
    public static function useScenario(
        string $scenario,
        array $configurationOverrides = [],
    ): string {
        self::configureDefaults();
        self::assertScenarioExists($scenario);

        $recordPath = storage_path(sprintf(
            'framework/testing/codex/records/%s-%s.jsonl',
            $scenario,
            Str::lower((string) Str::ulid()),
        ));

        $recordDirectory = dirname($recordPath);

        if (
            ! is_dir($recordDirectory)
            && ! mkdir($recordDirectory, 0777, true)
            && ! is_dir($recordDirectory)
        ) {
            throw new InvalidArgumentException(
                'Could not create the fake Codex record directory.',
            );
        }

        config()->set(
            'codex-app-server.arguments',
            [
                'app-server',
                '--listen',
                'stdio://',
                '--catalog',
                self::catalogPath(),
                '--scenario',
                $scenario,
                '--record',
                $recordPath,
            ],
        );

        foreach ($configurationOverrides as $key => $value) {
            config()->set(
                "codex-app-server.{$key}",
                $value,
            );
        }

        self::forgetSettings();

        return $recordPath;
    }

    /**
     * Build a valid immutable process context for a gateway contract test.
     */
    public static function context(): CodexProcessContext
    {
        $runId = Str::lower(
            (string) Str::ulid(),
        );

        $root = storage_path(
            "framework/testing/codex/runs/{$runId}",
        );

        $workspace = "{$root}/workspace";
        $codexHome = "{$root}/codex-home";

        foreach ([$workspace, $codexHome] as $directory) {
            if (
                ! is_dir($directory)
                && ! mkdir($directory, 0777, true)
                && ! is_dir($directory)
            ) {
                throw new InvalidArgumentException(
                    'Could not create a fake Codex runtime directory.',
                );
            }
        }

        return new CodexProcessContext(
            providerSessionId: (string) Str::ulid(),
            organizationId: 1,
            projectId: 1,
            executionId: (string) Str::ulid(),
            executionAttemptId: 248,
            workspacePath: $workspace,
            codexHomePath: $codexHome,
            modelIdentifier: 'gpt-5.3-codex',
            sandboxProfile: 'workspace-write',
            networkPolicy: 'denied',
            timeoutSeconds: 30,
        );
    }

    /**
     * Return the deterministic application clock used by contract tests.
     */
    public static function fixedClock(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            '2026-08-08T00:00:00+00:00',
        );
    }

    /**
     * Read every client JSONL message recorded by the fake executable.
     *
     * @return list<array<string, mixed>>
     */
    public static function recordedMessages(
        string $recordPath,
    ): array {
        if (! is_file($recordPath)) {
            return [];
        }

        $messages = [];

        foreach (
            file(
                $recordPath,
                FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES,
            ) ?: [] as $line
        ) {
            try {
                $message = json_decode(
                    $line,
                    true,
                    32,
                    JSON_THROW_ON_ERROR,
                );
            } catch (JsonException $exception) {
                throw new InvalidArgumentException(
                    'The fake Codex protocol record contains invalid JSON.',
                    previous: $exception,
                );
            }

            if (! is_array($message)) {
                throw new InvalidArgumentException(
                    'The fake Codex protocol record contains an invalid message.',
                );
            }

            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * Return the repository-owned fake executable path.
     */
    public static function executablePath(): string
    {
        return base_path(
            'tests/Fixtures/Codex/bin/fake-codex',
        );
    }

    /**
     * Return the versioned deterministic scenario catalog path.
     */
    public static function catalogPath(): string
    {
        return base_path(
            'tests/Fixtures/Codex/scenarios.json',
        );
    }

    /**
     * Forget the cached settings singleton after test configuration changes.
     */
    public static function forgetSettings(): void
    {
        app()->forgetInstance(
            CodexAppServerSettings::class,
        );
    }

    /**
     * Fail immediately when a test references an undefined scenario.
     */
    private static function assertScenarioExists(
        string $scenario,
    ): void {
        try {
            $catalog = json_decode(
                file_get_contents(
                    self::catalogPath(),
                ) ?: '',
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'The fake Codex scenario catalog contains invalid JSON.',
                previous: $exception,
            );
        }

        if (
            ! is_array($catalog)
            || ! isset($catalog['scenarios'][$scenario])
        ) {
            throw new InvalidArgumentException(
                "Unknown fake Codex scenario: {$scenario}",
            );
        }
    }
}
