<?php

declare(strict_types=1);

use App\Models\OfficeProjection;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Create a tenant-scoped project and office projection for performance tests.
 *
 * @return array{0: User, 1: Organization, 2: Project}
 */
function createPerformanceBaselineProject(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    OrganizationMembership::factory()
        ->owner()
        ->for($organization)
        ->for($user)
        ->create();

    $project = Project::factory()
        ->for($organization)
        ->create();

    $state = [
        'project' => [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'status' => $project->status->value,
            'archived' => false,
        ],
        'workflow' => null,
        'roadmap' => null,
        'summary' => [
            'activeAgents' => 0,
            'ticketsTotal' => 0,
            'ticketsByStatus' => [],
            'blockers' => 0,
            'pendingApprovals' => 0,
            'retriesScheduled' => 0,
            'recentDecisions' => 0,
        ],
        'rooms' => [],
        'agents' => [],
        'indicators' => [],
        'simulation' => [
            'labelRequired' => true,
            'executionProvider' => 'simulation',
            'actualState' => 'unverified',
        ],
    ];

    OfficeProjection::query()->create([
        'organization_id' => $organization->id,
        'project_id' => $project->id,
        'schema_version' => 1,
        'last_event_sequence' => 1,
        'last_event_id' => null,
        'fingerprint' => hash(
            'sha256',
            json_encode($state, JSON_THROW_ON_ERROR),
        ),
        'state' => $state,
        'projected_at' => now(),
    ]);

    return [$user, $organization, $project];
}

/**
 * Calculate a nearest-rank percentile.
 *
 * @param  list<float>  $values
 */
function performanceBaselinePercentile(
    array $values,
    float $percentile,
): float {
    sort($values, SORT_NUMERIC);

    $rank = max(
        0,
        min(
            count($values) - 1,
            (int) ceil($percentile * count($values)) - 1,
        ),
    );

    return $values[$rank];
}

/**
 * Measure one authenticated HTTP request.
 *
 * @return array{
 *     response: TestResponse,
 *     queryCount: int,
 *     latencyMs: float,
 *     payloadBytes: int
 * }
 */
function measureApplicationRequest(
    Closure $request,
): array {
    DB::flushQueryLog();
    DB::enableQueryLog();

    $startedAt = hrtime(true);

    /** @var TestResponse $response */
    $response = $request();

    $latencyMs = (hrtime(true) - $startedAt) / 1_000_000;

    $queryCount = count(DB::getQueryLog());

    DB::disableQueryLog();

    return [
        'response' => $response,
        'queryCount' => $queryCount,
        'latencyMs' => $latencyMs,
        'payloadBytes' => strlen($response->getContent()),
    ];
}

it('keeps key pages and projections inside configured budgets', function (): void {
    [$user, $organization, $project] =
        createPerformanceBaselineProject();

    $this->actingAs($user);

    $cases = [
        'operations_dashboard' => route(
            'organizations.projects.operations.index',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ),
        'operational_metrics' => route(
            'organizations.projects.operations.metrics.index',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ),
        'office_projection' => route(
            'organizations.projects.operations.office-projection.show',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ),
    ];

    $samples = max(
        3,
        (int) config('performance.samples', 5),
    );

    foreach ($cases as $name => $url) {
        /** @var array{
         *     max_queries: int,
         *     max_p95_latency_ms: int,
         *     max_payload_bytes: int
         * } $budget
         */
        $budget = config(sprintf('performance.server.%s', $name));

        $queryCounts = [];
        $latencies = [];
        $payloadSizes = [];

        for ($sample = 0; $sample < $samples; $sample++) {
            $measurement = measureApplicationRequest(
                fn (): TestResponse => $this->get($url),
            );

            $measurement['response']->assertSuccessful();

            $queryCounts[] = $measurement['queryCount'];
            $latencies[] = $measurement['latencyMs'];
            $payloadSizes[] = $measurement['payloadBytes'];
        }

        expect(max($queryCounts))
            ->toBeLessThanOrEqual($budget['max_queries']);

        expect(
            performanceBaselinePercentile($latencies, 0.95),
        )->toBeLessThanOrEqual(
            (float) $budget['max_p95_latency_ms'],
        );

        expect(max($payloadSizes))
            ->toBeLessThanOrEqual($budget['max_payload_bytes']);
    }
});
