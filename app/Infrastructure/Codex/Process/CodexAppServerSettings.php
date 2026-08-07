<?php

declare(strict_types=1);

namespace App\Infrastructure\Codex\Process;

use InvalidArgumentException;

/**
 * Holds validated process and protocol limits for Codex App Server.
 */
final readonly class CodexAppServerSettings
{
    /**
     * @param  list<string>  $arguments
     * @param  list<string>  $versionArguments
     * @param  list<string>  $allowedEnvironment
     * @param  list<string>  $allowedServerMethods
     */
    private function __construct(
        public string $executable,
        public array $arguments,
        public array $versionArguments,
        public string $expectedBinaryVersion,
        public string $protocolVersion,
        public string $schemaFingerprint,
        public int $startupTimeoutSeconds,
        public int $requestTimeoutSeconds,
        public int $shutdownGraceSeconds,
        public int $maximumMessageBytes,
        public int $maximumEvents,
        public int $maximumStderrBytes,
        public int $maximumJsonDepth,
        public array $allowedEnvironment,
        public array $allowedServerMethods,
    ) {}

    /**
     * Build validated settings from application configuration.
     */
    public static function fromConfiguration(): self
    {
        $configuration = config('codex-app-server');

        if (! is_array($configuration)) {
            throw new InvalidArgumentException(
                'Codex App Server configuration must be an array.',
            );
        }

        $executable = self::requiredString(
            $configuration['executable'] ?? null,
            'executable',
        );

        if (
            ! str_starts_with($executable, '/')
            || ! is_file($executable)
            || ! is_executable($executable)
        ) {
            throw new InvalidArgumentException(
                'Codex App Server executable must be an executable absolute path.',
            );
        }

        $schemaFingerprint = self::requiredString(
            $configuration['schema_fingerprint'] ?? null,
            'schema fingerprint',
        );

        if (
            preg_match(
                '/\A[a-f0-9]{64}\z/D',
                $schemaFingerprint,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Codex App Server schema fingerprint must be SHA-256.',
            );
        }

        return new self(
            executable: $executable,
            arguments: self::stringList(
                $configuration['arguments'] ?? null,
                'arguments',
            ),
            versionArguments: self::stringList(
                $configuration['version_arguments'] ?? null,
                'version arguments',
            ),
            expectedBinaryVersion: self::requiredString(
                $configuration['expected_binary_version'] ?? null,
                'expected binary version',
            ),
            protocolVersion: self::requiredString(
                $configuration['protocol_version'] ?? null,
                'protocol version',
            ),
            schemaFingerprint: $schemaFingerprint,
            startupTimeoutSeconds: self::positiveInteger(
                $configuration['startup_timeout_seconds'] ?? null,
                'startup timeout',
            ),
            requestTimeoutSeconds: self::positiveInteger(
                $configuration['request_timeout_seconds'] ?? null,
                'request timeout',
            ),
            shutdownGraceSeconds: self::positiveInteger(
                $configuration['shutdown_grace_seconds'] ?? null,
                'shutdown grace',
            ),
            maximumMessageBytes: self::positiveInteger(
                $configuration['maximum_message_bytes'] ?? null,
                'maximum message size',
            ),
            maximumEvents: self::positiveInteger(
                $configuration['maximum_events'] ?? null,
                'maximum event count',
            ),
            maximumStderrBytes: self::positiveInteger(
                $configuration['maximum_stderr_bytes'] ?? null,
                'maximum stderr size',
            ),
            maximumJsonDepth: self::positiveInteger(
                $configuration['maximum_json_depth'] ?? null,
                'maximum JSON depth',
            ),
            allowedEnvironment: self::stringList(
                $configuration['allowed_environment'] ?? null,
                'environment allowlist',
            ),
            allowedServerMethods: self::stringList(
                $configuration['allowed_server_methods'] ?? null,
                'server method allowlist',
            ),
        );
    }

    /**
     * Build a deny-by-default child-process environment.
     *
     * @return array<string, string|false>
     */
    public function environment(
        string $codexHome,
    ): array {
        $current = getenv();
        $environment = [];

        foreach (array_keys($current) as $name) {
            $environment[$name] = false;
        }

        foreach ($this->allowedEnvironment as $name) {
            $value = $current[$name] ?? null;

            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        $environment['CODEX_HOME'] = $codexHome;

        return $environment;
    }

    /**
     * Validate one required string.
     */
    private static function requiredString(
        mixed $value,
        string $label,
    ): string {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                "Codex App Server {$label} is required.",
            );
        }

        return trim($value);
    }

    /**
     * Validate one list containing only non-empty strings.
     *
     * @return list<string>
     */
    private static function stringList(
        mixed $value,
        string $label,
    ): array {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException(
                "Codex App Server {$label} must be a list.",
            );
        }

        $result = [];

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(
                    "Codex App Server {$label} contains an invalid value.",
                );
            }

            $result[] = trim($item);
        }

        return $result;
    }

    /**
     * Validate one positive integer.
     */
    private static function positiveInteger(
        mixed $value,
        string $label,
    ): int {
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException(
                "Codex App Server {$label} must be positive.",
            );
        }

        return $value;
    }
}
