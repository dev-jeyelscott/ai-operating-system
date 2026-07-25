<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Workflows\WorkflowDefinitionManifest;
use App\Models\WorkflowDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowDefinition>
 */
final class WorkflowDefinitionFactory extends Factory
{
    /**
     * Define a valid immutable workflow-definition version.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $manifest = new WorkflowDefinitionManifest(
            definitionKey: strtolower(
                fake()->unique()->lexify('workflow_????????'),
            ),
            version: 1,
            schemaVersion: 1,
            name: rtrim(fake()->sentence(3), '.'),
            description: fake()->sentence(),
            initialState: 'queued',
            states: [
                'queued',
                'running',
                'completed',
                'failed',
            ],
            terminalStates: [
                'completed',
                'failed',
            ],
            transitions: [
                [
                    'name' => 'start',
                    'from' => 'queued',
                    'to' => 'running',
                    'guard' => null,
                ],
                [
                    'name' => 'complete',
                    'from' => 'running',
                    'to' => 'completed',
                    'guard' => 'required_outputs_exist',
                ],
                [
                    'name' => 'fail',
                    'from' => 'running',
                    'to' => 'failed',
                    'guard' => null,
                ],
            ],
        );

        return [
            'definition_key' => $manifest->definitionKey,
            'version' => $manifest->version,
            'schema_version' => $manifest->schemaVersion,
            'name' => $manifest->name,
            'description' => $manifest->description,
            'definition' => $manifest->definition(),
            'checksum_sha256' => $manifest->checksumSha256(),
            'created_at' => now(),
        ];
    }
}
