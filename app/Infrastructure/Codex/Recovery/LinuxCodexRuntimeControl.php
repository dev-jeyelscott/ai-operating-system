<?php

declare(strict_types=1);

namespace App\Infrastructure\Codex\Recovery;

use App\Application\Codex\Contracts\CodexRuntimeControl;
use App\Application\Codex\Data\CodexRuntimeIdentity;
use App\Domain\Codex\CodexRuntimeState;
use App\Models\ProviderSession;

/**
 * Inspects and terminates Codex processes inside the current Linux PID namespace.
 */
final readonly class LinuxCodexRuntimeControl implements CodexRuntimeControl
{
    /**
     * Create the local Linux runtime controller.
     */
    public function __construct(
        private string $procRoot = '/proc',
    ) {}

    /**
     * Capture one process identity while the owning worker still controls it.
     *
     * @phpstan-impure
     */
    public function captureIdentity(
        int $processId,
    ): ?CodexRuntimeIdentity {
        if ($processId < 1) {
            return null;
        }

        $fingerprint = $this->fingerprint(
            $processId,
        );

        if ($fingerprint === null) {
            return null;
        }

        return new CodexRuntimeIdentity(
            hostId: $this->hostId(),
            fingerprint: $fingerprint,
        );
    }

    /**
     * Inspect only the exact recorded process.
     *
     * Process state is external to PHP and may change between consecutive
     * invocations even when the same ProviderSession object is supplied.
     *
     * @phpstan-impure
     */
    public function inspect(
        ProviderSession $session,
    ): CodexRuntimeState {
        $processId = $session->runtime_process_id;
        $hostId = $session->runtime_host_id;
        $expectedFingerprint = $session
            ->runtime_identity_fingerprint;

        if (
            $processId === null
            || $hostId === null
            || $expectedFingerprint === null
        ) {
            return CodexRuntimeState::Unreachable;
        }

        if (! hash_equals($hostId, $this->hostId())) {
            return CodexRuntimeState::Unreachable;
        }

        $processDirectory = $this->procRoot
            .DIRECTORY_SEPARATOR
            .$processId;

        if (! is_dir($processDirectory)) {
            return CodexRuntimeState::Exited;
        }

        $currentFingerprint = $this->fingerprint(
            $processId,
        );

        if ($currentFingerprint === null) {
            return CodexRuntimeState::Unreachable;
        }

        if (
            ! hash_equals(
                $expectedFingerprint,
                $currentFingerprint,
            )
        ) {
            return CodexRuntimeState::IdentityMismatch;
        }

        return CodexRuntimeState::Running;
    }

    /**
     * Send SIGTERM and then SIGKILL only after exact identity verification.
     *
     * @phpstan-impure
     */
    public function terminate(
        ProviderSession $session,
        int $graceSeconds,
    ): CodexRuntimeState {
        $initialState = $this->inspect(
            $session,
        );

        if ($initialState !== CodexRuntimeState::Running) {
            return $initialState;
        }

        if (! function_exists('posix_kill')) {
            return CodexRuntimeState::Unreachable;
        }

        $processId = $session->runtime_process_id;

        if ($processId === null) {
            return CodexRuntimeState::Unreachable;
        }

        if (! @posix_kill($processId, 15)) {
            return $this->inspect(
                $session,
            );
        }

        $deadline = hrtime(true)
            + ($graceSeconds * 1_000_000_000);

        while (hrtime(true) < $deadline) {
            $state = $this->inspect(
                $session,
            );

            if (
                $state === CodexRuntimeState::Exited
                || $state === CodexRuntimeState::IdentityMismatch
            ) {
                /*
                 * Identity mismatch after signalling the verified original
                 * process means that original PID has exited and may already
                 * have been reused. Never signal the replacement process.
                 */
                return CodexRuntimeState::Exited;
            }

            if ($state !== CodexRuntimeState::Running) {
                return $state;
            }

            usleep(50_000);
        }

        /*
         * Re-check exact identity immediately before SIGKILL so a recycled PID
         * can never receive the forced signal.
         */
        if (
            $this->inspect($session)
            !== CodexRuntimeState::Running
        ) {
            return CodexRuntimeState::Exited;
        }

        if (! @posix_kill($processId, 9)) {
            return $this->inspect(
                $session,
            );
        }

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $state = $this->inspect(
                $session,
            );

            if (
                $state === CodexRuntimeState::Exited
                || $state === CodexRuntimeState::IdentityMismatch
            ) {
                return CodexRuntimeState::Exited;
            }

            if ($state !== CodexRuntimeState::Running) {
                return $state;
            }

            usleep(50_000);
        }

        return $this->inspect(
            $session,
        );
    }

    /**
     * Build a process fingerprint from Linux kernel-owned process metadata.
     */
    private function fingerprint(
        int $processId,
    ): ?string {
        $bootId = $this->read(
            $this->procRoot
                .'/sys/kernel/random/boot_id',
        );

        $stat = $this->read(
            $this->procRoot
                .'/'.$processId.'/stat',
        );

        $executable = @readlink(
            $this->procRoot
                .'/'.$processId.'/exe',
        );

        $workingDirectory = @readlink(
            $this->procRoot
                .'/'.$processId.'/cwd',
        );

        if (
            $bootId === null
            || $stat === null
            || ! is_string($executable)
            || ! is_string($workingDirectory)
        ) {
            return null;
        }

        $closingParenthesis = strrpos(
            $stat,
            ')',
        );

        if ($closingParenthesis === false) {
            return null;
        }

        $remainingFields = preg_split(
            '/\s+/',
            trim(substr(
                $stat,
                $closingParenthesis + 1,
            )),
        );

        /*
         * After removing fields 1 and 2, index 19 corresponds to Linux proc
         * stat field 22: the process start time in clock ticks.
         */
        $startTicks = is_array($remainingFields)
            ? ($remainingFields[19] ?? null)
            : null;

        if (
            ! is_string($startTicks)
            || $startTicks === ''
        ) {
            return null;
        }

        return hash(
            'sha256',
            implode(
                "\n",
                [
                    trim($bootId),
                    (string) $processId,
                    $startTicks,
                    $executable,
                    $workingDirectory,
                ],
            ),
        );
    }

    /**
     * Return the current runtime host identity.
     */
    private function hostId(): string
    {
        $host = gethostname();

        if (
            ! is_string($host)
            || trim($host) === ''
        ) {
            $host = php_uname('n');
        }

        return trim($host);
    }

    /**
     * Read one local procfs value.
     */
    private function read(
        string $path,
    ): ?string {
        $value = @file_get_contents(
            $path,
        );

        if (! is_string($value)) {
            return null;
        }

        return $value;
    }
}
