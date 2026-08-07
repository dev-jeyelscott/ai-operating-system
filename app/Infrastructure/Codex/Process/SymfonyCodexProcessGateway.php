<?php

declare(strict_types=1);

namespace App\Infrastructure\Codex\Process;

use App\Application\Codex\Contracts\CodexProcessGateway;
use App\Application\Codex\Contracts\CodexProcessSession;
use App\Application\Codex\Data\CodexProcessContext;
use App\Application\Codex\Exceptions\CodexGatewayException;
use App\Application\Security\RedactSensitiveData;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Starts one isolated, bounded App Server process using structured arguments.
 */
final readonly class SymfonyCodexProcessGateway implements CodexProcessGateway
{
    /**
     * Inject validated process settings and security redaction.
     */
    public function __construct(
        private CodexAppServerSettings $settings,
        private RedactSensitiveData $redactor,
    ) {}

    /**
     * Verify the pinned binary and start one dedicated App Server process.
     */
    public function start(
        CodexProcessContext $context,
    ): CodexProcessSession {
        $this->assertRuntimeDirectory(
            $context->workspacePath,
            'workspace',
        );

        $this->assertRuntimeDirectory(
            $context->codexHomePath,
            'Codex home',
        );

        $environment = $this->settings->environment(
            $context->codexHomePath,
        );

        $this->verifyBinaryVersion(
            $environment,
        );

        $input = new InputStream;

        $process = new Process(
            command: [
                $this->settings->executable,
                ...$this->settings->arguments,
            ],
            cwd: $context->workspacePath,
            env: $environment,
        );

        $process->setInput($input);
        $process->setTimeout(
            $context->timeoutSeconds,
        );

        try {
            $process->start();
        } catch (Throwable $exception) {
            throw new CodexGatewayException(
                CodexGatewayException::STARTUP_FAILED,
                true,
                'Codex App Server could not be started.',
                $exception,
            );
        }

        if (! $process->isRunning()) {
            throw new CodexGatewayException(
                CodexGatewayException::STARTUP_FAILED,
                true,
                'Codex App Server exited during startup.',
            );
        }

        return new SymfonyCodexProcessSession(
            context: $context,
            settings: $this->settings,
            process: $process,
            input: $input,
            decoder: new CodexJsonRpcDecoder(
                maximumMessageBytes: $this->settings
                    ->maximumMessageBytes,
                maximumJsonDepth: $this->settings
                    ->maximumJsonDepth,
            ),
            redactor: $this->redactor,
        );
    }

    /**
     * Verify the executable reports the explicitly pinned Codex version.
     *
     * @param  array<string, string|false>  $environment
     */
    private function verifyBinaryVersion(
        array $environment,
    ): void {
        $process = new Process(
            command: [
                $this->settings->executable,
                ...$this->settings->versionArguments,
            ],
            env: $environment,
        );

        $process->setTimeout(
            $this->settings->startupTimeoutSeconds,
        );

        try {
            $process->run();
        } catch (Throwable $exception) {
            throw new CodexGatewayException(
                CodexGatewayException::STARTUP_FAILED,
                true,
                'Codex binary version inspection failed.',
                $exception,
            );
        }

        if (! $process->isSuccessful()) {
            throw new CodexGatewayException(
                CodexGatewayException::STARTUP_FAILED,
                true,
                'Codex binary version inspection failed.',
            );
        }

        $version = trim(
            $this->redactor->message(
                $process->getOutput(),
            ),
        );

        if (
            ! str_contains(
                $version,
                $this->settings->expectedBinaryVersion,
            )
        ) {
            throw new CodexGatewayException(
                CodexGatewayException::VERSION_MISMATCH,
                false,
                'Codex binary version does not match the approved version.',
            );
        }
    }

    /**
     * Reject missing, symlinked, or non-directory runtime roots.
     */
    private function assertRuntimeDirectory(
        string $path,
        string $label,
    ): void {
        if (
            ! is_dir($path)
            || is_link($path)
            || realpath($path) === false
        ) {
            throw new CodexGatewayException(
                CodexGatewayException::STARTUP_FAILED,
                false,
                "Codex {$label} directory is invalid.",
            );
        }
    }
}
