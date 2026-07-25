<?php

declare(strict_types=1);

namespace App\Application\Workflows;

use App\Application\Shared\Contracts\TransactionManager;
use App\Application\Shared\Exceptions\ConflictException;
use App\Domain\Workflows\WorkflowDefinitionManifest;
use App\Models\WorkflowDefinition;

/**
 * Publishes one immutable workflow-definition version.
 */
final readonly class RegisterWorkflowDefinition
{
    /**
     * Inject the application transaction boundary.
     */
    public function __construct(
        private TransactionManager $transactions,
    ) {}

    /**
     * Create the requested version once or return the identical existing row.
     */
    public function handle(
        WorkflowDefinitionManifest $manifest,
    ): WorkflowDefinition {
        return $this->transactions->run(
            function () use ($manifest): WorkflowDefinition {
                $checksum = $manifest->checksumSha256();

                $definition = WorkflowDefinition::query()->createOrFirst(
                    [
                        'definition_key' => $manifest->definitionKey,
                        'version' => $manifest->version,
                    ],
                    [
                        'schema_version' => $manifest->schemaVersion,
                        'name' => $manifest->name,
                        'description' => $manifest->description,
                        'definition' => $manifest->definition(),
                        'checksum_sha256' => $checksum,
                        'created_at' => now(),
                    ],
                );

                if (! hash_equals(
                    $definition->checksum_sha256,
                    $checksum,
                )) {
                    throw new ConflictException(sprintf(
                        'Workflow definition "%s" version %d already exists with different content.',
                        $manifest->definitionKey,
                        $manifest->version,
                    ));
                }

                return $definition;
            },
        );
    }
}
