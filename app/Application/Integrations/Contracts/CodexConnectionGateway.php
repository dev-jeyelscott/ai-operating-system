<?php

declare(strict_types=1);

namespace App\Application\Integrations\Contracts;

use App\Application\Integrations\CodexConnectionTestResult;
use App\Domain\Integrations\IntegrationCredentialSecret;

/**
 * Performs a bounded, read-only Codex credential/model preflight.
 *
 * This is deliberately not an execution-provider gateway and cannot modify a
 * repository, create a workspace, execute commands, or advance workflow state.
 */
interface CodexConnectionGateway
{
    /**
     * Verify that the credential can retrieve the configured model metadata.
     */
    public function test(
        IntegrationCredentialSecret $credential,
        string $modelIdentifier,
    ): CodexConnectionTestResult;
}
