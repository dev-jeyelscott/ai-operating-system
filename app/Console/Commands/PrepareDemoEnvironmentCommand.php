<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DeterministicDemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Prepares and validates the deterministic local demonstration environment.
 */
#[Signature(
    'app:demo:prepare
        {--json : Print a machine-readable demo environment manifest}',
)]
#[Description(
    'Seed and validate the deterministic local AI Operating System demonstration.',
)]
final class PrepareDemoEnvironmentCommand extends Command
{
    private const int MANIFEST_SCHEMA_VERSION = 1;

    private const string DEMO_EMAIL = 'demo-owner@example.test';

    private const string DEMO_PASSWORD = 'Demo-Password-2026';

    private const string ORGANIZATION_SLUG = 'aios-demonstration';

    /**
     * Stable project slugs currently owned by DeterministicDemoSeeder.
     *
     * @var list<string>
     */
    private const array PROJECT_SLUGS = [
        'demo-happy-path',
        'demo-conflicting-documents',
        'demo-notion-transient-failure',
        'demo-high-risk-merge',
    ];

    /**
     * Seed, validate, and describe the deterministic demonstration.
     */
    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->components->error(
                'The deterministic demo may only be prepared in local or testing environments.',
            );

            return self::FAILURE;
        }

        try {
            $seedExitCode = $this->callSilent('db:seed', [
                '--class' => DeterministicDemoSeeder::class,
                '--force' => true,
            ]);

            if ($seedExitCode !== self::SUCCESS) {
                throw new RuntimeException(
                    'The deterministic demo seeder did not complete successfully.',
                );
            }

            $manifest = $this->buildManifest();

            if ($this->option('json')) {
                $this->line($this->encode($manifest));

                return self::SUCCESS;
            }

            $this->renderManifest($manifest);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Validate the stored demo records and construct their public local manifest.
     *
     * @return array{
     *     schemaVersion: int,
     *     environment: string,
     *     simulation: array{
     *         provider: string,
     *         actualState: string,
     *         evidenceStillRequired: bool
     *     },
     *     credentials: array{
     *         email: string,
     *         password: string
     *     },
     *     organization: array{
     *         name: string,
     *         slug: string
     *     },
     *     loginUrl: string,
     *     projects: list<array{
     *         name: string,
     *         slug: string,
     *         status: string,
     *         url: string
     *     }>,
     *     nextCommand: string
     * }
     */
    private function buildManifest(): array
    {
        $user = User::query()
            ->where('email', self::DEMO_EMAIL)
            ->first();

        if (! $user instanceof User) {
            throw new RuntimeException(
                'The deterministic demo user was not created.',
            );
        }

        $organization = Organization::query()
            ->where('slug', self::ORGANIZATION_SLUG)
            ->first();

        if (! $organization instanceof Organization) {
            throw new RuntimeException(
                'The deterministic demo organization was not created.',
            );
        }

        $membershipExists = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $membershipExists) {
            throw new RuntimeException(
                'The deterministic demo owner membership was not created.',
            );
        }

        $projects = Project::query()
            ->forOrganization($organization->id)
            ->whereIn('slug', self::PROJECT_SLUGS)
            ->orderBy('slug')
            ->get();

        if ($projects->count() !== count(self::PROJECT_SLUGS)) {
            throw new RuntimeException(sprintf(
                'Expected %d deterministic demo projects, but found %d.',
                count(self::PROJECT_SLUGS),
                $projects->count(),
            ));
        }

        $missingSlugs = array_values(array_diff(
            self::PROJECT_SLUGS,
            $projects->pluck('slug')->all(),
        ));

        if ($missingSlugs !== []) {
            throw new RuntimeException(sprintf(
                'The following deterministic demo projects are missing: %s.',
                implode(', ', $missingSlugs),
            ));
        }

        $projectIds = $projects->pluck('id')->all();

        $documentVersions = DocumentVersion::query()
            ->whereHas(
                'document',
                fn ($query) => $query->whereIn('project_id', $projectIds),
            )
            ->get();

        if ($documentVersions->count() !== 11) {
            throw new RuntimeException(sprintf(
                'Expected 11 deterministic demo document versions, but found %d.',
                $documentVersions->count(),
            ));
        }

        $hasMissingSimulationLabels = $documentVersions->contains(
            static function (DocumentVersion $version): bool {
                $flags = $version->analysis_flags;

                return ! is_array($flags)
                    || ! in_array('simulation_only', $flags, true)
                    || ! in_array(
                        'actual_state_unverified',
                        $flags,
                        true,
                    );
            },
        );

        if ($hasMissingSimulationLabels) {
            throw new RuntimeException(
                'One or more demo documents are missing mandatory simulation labels.',
            );
        }

        return [
            'schemaVersion' => self::MANIFEST_SCHEMA_VERSION,
            'environment' => app()->environment(),
            'simulation' => [
                'provider' => 'simulation',
                'actualState' => 'unverified',
                'evidenceStillRequired' => true,
            ],
            'credentials' => [
                'email' => self::DEMO_EMAIL,
                'password' => self::DEMO_PASSWORD,
            ],
            'organization' => [
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'loginUrl' => route('login'),
            'projects' => $projects
                ->map(
                    static fn (Project $project): array => [
                        'name' => $project->name,
                        'slug' => $project->slug,
                        'status' => $project->status->value,
                        'url' => route(
                            'organizations.projects.show',
                            [
                                'organization' => $organization,
                                'project' => $project,
                            ],
                        ),
                    ],
                )
                ->values()
                ->all(),
            'nextCommand' => './bin/dev',
        ];
    }

    /**
     * Render a human-readable local demo summary.
     *
     * @param array{
     *     credentials: array{email: string, password: string},
     *     organization: array{name: string, slug: string},
     *     loginUrl: string,
     *     projects: list<array{
     *         name: string,
     *         slug: string,
     *         status: string,
     *         url: string
     *     }>,
     *     nextCommand: string
     * } $manifest
     */
    private function renderManifest(array $manifest): void
    {
        $this->newLine();
        $this->components->info(
            'Deterministic demo environment prepared successfully.',
        );

        $this->table(
            ['Field', 'Value'],
            [
                ['Login URL', $manifest['loginUrl']],
                ['Email', $manifest['credentials']['email']],
                ['Password', $manifest['credentials']['password']],
                [
                    'Organization',
                    $manifest['organization']['name'],
                ],
                ['Next command', $manifest['nextCommand']],
                ['Evidence state', 'Simulated and unverified'],
            ],
        );

        $this->table(
            ['Project', 'Status', 'URL'],
            array_map(
                static fn (array $project): array => [
                    $project['name'],
                    $project['status'],
                    $project['url'],
                ],
                $manifest['projects'],
            ),
        );

        $this->components->warn(
            'Demo activity is simulated. No verified repository, CI, merge, or deployment evidence is created.',
        );
    }

    /**
     * Encode the manifest without losing Unicode or URL readability.
     *
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                    | JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The deterministic demo manifest could not be encoded.',
                previous: $exception,
            );
        }
    }
}
