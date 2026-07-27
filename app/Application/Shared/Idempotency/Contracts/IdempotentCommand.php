<?php

declare(strict_types=1);

namespace App\Application\Shared\Idempotency\Contracts;

use App\Application\Shared\Commands\Command;

/**
 * Marks an application command as requiring durable replay protection.
 *
 * Cache locks and result replay coordinate concurrent callers, but are not a
 * business uniqueness guarantee. Each state-changing handler must persist a
 * unique business identity and reconcile an already committed result before
 * creating any new side effect.
 */
interface IdempotentCommand extends Command
{
    /**
     * Return the caller-supplied idempotency key.
     *
     * The raw value is never persisted. The infrastructure stores only its
     * SHA-256 hash.
     */
    public function idempotencyKey(): string;

    /**
     * Return the tenant-aware operation scope.
     *
     * Recommended format:
     * organization:{organizationId}:project:{projectId}:{operation}
     */
    public function idempotencyScope(): string;

    /**
     * Return deterministic, non-secret command inputs used for fingerprinting.
     *
     * Never include access tokens, credentials, private document contents,
     * raw uploaded files, or other secrets.
     *
     * @return array<string, mixed>
     */
    public function idempotencyPayload(): array;
}
