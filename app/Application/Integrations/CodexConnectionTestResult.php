<?php

declare(strict_types=1);

namespace App\Application\Integrations;

use App\Domain\Integrations\CodexConnectionFailureCode;

/**
 * Contains one sanitized, immutable Codex connection-test result.
 */
final readonly class CodexConnectionTestResult
{
    /**
     * Create an immutable result object.
     */
    private function __construct(
        public bool $successful,
        public string $modelIdentifier,
        public ?CodexConnectionFailureCode $failureCode,
        public ?string $providerRequestId,
        public bool $configurationChanged,
    ) {}

    /**
     * Create a successful, sanitized result.
     */
    public static function connected(
        string $modelIdentifier,
        ?string $providerRequestId,
    ): self {
        return new self(
            successful: true,
            modelIdentifier: $modelIdentifier,
            failureCode: null,
            providerRequestId: $providerRequestId,
            configurationChanged: false,
        );
    }

    /**
     * Create a failed, sanitized result.
     */
    public static function failed(
        CodexConnectionFailureCode $failureCode,
        string $modelIdentifier,
        ?string $providerRequestId = null,
    ): self {
        return new self(
            successful: false,
            modelIdentifier: $modelIdentifier,
            failureCode: $failureCode,
            providerRequestId: $providerRequestId,
            configurationChanged: false,
        );
    }

    /**
     * Attach whether safe persisted integration metadata materially changed.
     */
    public function withConfigurationChanged(bool $configurationChanged): self
    {
        return new self(
            successful: $this->successful,
            modelIdentifier: $this->modelIdentifier,
            failureCode: $this->failureCode,
            providerRequestId: $this->providerRequestId,
            configurationChanged: $configurationChanged,
        );
    }

    /**
     * Return a stable user-facing message without provider-controlled text.
     */
    public function userMessage(): string
    {
        return $this->failureCode?->userMessage()
            ?? 'The Codex credential and configured model were validated successfully.';
    }
}
