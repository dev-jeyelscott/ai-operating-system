<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Projects\Configuration\AutonomyLevel;
use App\Domain\Projects\Configuration\ProjectConfigurationSchema;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Domain\Projects\Configuration\RepositoryProvider;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectConfiguration>
 */
final class ProjectConfigurationFactory extends Factory
{
    /** @var class-string<ProjectConfiguration> */
    protected $model = ProjectConfiguration::class;

    /**
     * Define a valid but intentionally incomplete project configuration.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'schema_version' => ProjectConfigurationSchema::CURRENT_VERSION,
            'revision' => ProjectConfigurationSchema::INITIAL_REVISION,
            'technology_stack' => ProjectConfigurationSchema::technologyStackDefaults(),
            'repository_provider' => null,
            'repository_url' => null,
            'default_branch' => null,
            'integration_branch' => 'develop',
            'build_command' => null,
            'test_command' => null,
            'lint_command' => null,
            'static_analysis_command' => null,
            'security_command' => null,
            'required_documents' => [],
            'default_reasoning' => ReasoningLevel::Medium,
            'provider_policy' => ProjectConfigurationSchema::providerPolicyDefaults(),
            'budget_limit_minor' => null,
            'budget_currency' => 'USD',
            'automatic_retry_limit' => 3,
            'autonomy_level' => AutonomyLevel::ApprovalRequired,
            'approval_policy' => ProjectConfigurationSchema::approvalPolicyDefaults(),
            'notification_policy' => ProjectConfigurationSchema::notificationPolicyDefaults(),
        ];
    }

    /**
     * Populate every schema-v1 section for serialization tests.
     */
    public function complete(): static
    {
        return $this->state(
            fn (): array => [
                'technology_stack' => [
                    'languages' => [
                        'PHP',
                        'TypeScript',
                    ],
                    'frameworks' => [
                        'Laravel',
                        'Inertia.js',
                        'React',
                    ],
                    'databases' => [
                        'PostgreSQL',
                    ],
                    'infrastructure' => [
                        'Docker Compose',
                        'Redis',
                    ],
                    'package_managers' => [
                        'Composer',
                        'pnpm',
                    ],
                    'runtimes' => [
                        'PHP 8.5',
                        'Node.js',
                    ],
                ],
                'repository_provider' => RepositoryProvider::GitHub,
                'repository_url' => 'https://github.com/example/project',
                'default_branch' => 'main',
                'integration_branch' => 'develop',
                'build_command' => 'pnpm run build',
                'test_command' => 'php artisan test',
                'lint_command' => 'composer lint:check',
                'static_analysis_command' => 'composer types:check',
                'security_command' => 'composer audit',
                'required_documents' => [
                    'product_charter',
                    'requirements',
                    'architecture',
                ],
                'default_reasoning' => ReasoningLevel::High,
                'provider_policy' => [
                    'allowed_provider_ids' => [
                        'simulation',
                    ],
                    'fallback_order' => [
                        'simulation',
                    ],
                ],
                'budget_limit_minor' => 5000,
                'budget_currency' => 'USD',
                'automatic_retry_limit' => 3,
                'autonomy_level' => AutonomyLevel::ApprovalRequired,
                'approval_policy' => ProjectConfigurationSchema::approvalPolicyDefaults(),
                'notification_policy' => [
                    'channels' => [
                        'in_app',
                    ],
                    'events' => [
                        'approval_requested',
                        'workflow_blocked',
                    ],
                ],
            ],
        );
    }
}
