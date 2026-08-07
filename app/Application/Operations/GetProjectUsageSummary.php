<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates project execution usage without creating a second cost ledger.
 */
final readonly class GetProjectUsageSummary
{
    private const string CURRENCY_EXPRESSION =
        "COALESCE(NULLIF(UPPER(execution_attempts.cost_currency), ''), 'UNSPECIFIED')";

    /**
     * Return cost and usage grouped by currency, provider, role, and reasoning.
     *
     * @return array<string, mixed>
     */
    public function handle(
        int $organizationId,
        int $projectId,
    ): array {
        $asOf = CarbonImmutable::now();

        $project = Project::query()
            ->forOrganization($organizationId)
            ->whereKey($projectId)
            ->firstOrFail();

        $base = $this->baseQuery($project->id);

        $attemptCount = (clone $base)
            ->count('execution_attempts.id');

        $executionCount = (clone $base)
            ->distinct()
            ->count('execution_attempts.execution_id');

        $summary = $this->summaryByCurrency($base);

        $quality = [
            'simulationActualCostRecords' => (clone $base)
                ->where(
                    'execution_attempts.execution_provider',
                    'simulation',
                )
                ->whereNotNull('execution_attempts.actual_cost')
                ->where('execution_attempts.actual_cost', '>', 0)
                ->count(),
            'missingCurrencyRecords' => (clone $base)
                ->where(static function (Builder $query): void {
                    $query
                        ->whereNotNull(
                            'execution_attempts.estimated_cost',
                        )
                        ->orWhereNotNull(
                            'execution_attempts.actual_cost',
                        );
                })
                ->where(static function (Builder $query): void {
                    $query
                        ->whereNull(
                            'execution_attempts.cost_currency',
                        )
                        ->orWhere(
                            'execution_attempts.cost_currency',
                            '',
                        );
                })
                ->count(),
        ];

        $core = [
            'summary' => [
                'attempts' => $attemptCount,
                'executions' => $executionCount,
                'currencies' => $summary,
            ],
            'byProvider' => $this->breakdown(
                base: $base,
                dimensionExpression: "COALESCE(NULLIF(execution_attempts.execution_provider, ''), 'unknown')",
                key: 'provider',
            ),
            'byRole' => $this->breakdown(
                base: $base,
                dimensionExpression: "COALESCE(NULLIF(executions.logical_role, ''), 'unassigned')",
                key: 'role',
            ),
            'byReasoning' => $this->breakdown(
                base: $base,
                dimensionExpression: "COALESCE(NULLIF(execution_attempts.effective_reasoning_level, ''), 'unknown')",
                key: 'reasoning',
            ),
            'dataQuality' => $quality,
        ];

        return [
            'metadata' => [
                'asOf' => $asOf->toIso8601String(),
                'fingerprint' => hash(
                    'sha256',
                    json_encode($core, JSON_THROW_ON_ERROR),
                ),
            ],
            ...$core,
        ];
    }

    /**
     * Return the shared project-scoped attempt query.
     */
    private function baseQuery(int $projectId): Builder
    {
        return DB::table('execution_attempts')
            ->join(
                'executions',
                'executions.id',
                '=',
                'execution_attempts.execution_id',
            )
            ->where('executions.project_id', $projectId);
    }

    /**
     * Return separate project totals for every persisted currency.
     *
     * @return list<array<string, int|string>>
     */
    private function summaryByCurrency(Builder $base): array
    {
        $rows = (clone $base)
            ->selectRaw(
                self::CURRENCY_EXPRESSION.' AS currency',
            )
            ->selectRaw('COUNT(*) AS attempt_count')
            ->selectRaw(
                'COUNT(DISTINCT execution_attempts.execution_id) AS execution_count',
            )
            ->selectRaw(
                "COALESCE(SUM(CASE
                    WHEN execution_attempts.execution_provider = 'simulation'
                    THEN COALESCE(execution_attempts.estimated_cost, 0)
                    ELSE 0
                END), 0) AS estimated_simulation_cost",
            )
            ->selectRaw(
                "COALESCE(SUM(CASE
                    WHEN execution_attempts.execution_provider <> 'simulation'
                    THEN COALESCE(execution_attempts.estimated_cost, 0)
                    ELSE 0
                END), 0) AS estimated_provider_cost",
            )
            ->selectRaw(
                "COALESCE(SUM(CASE
                    WHEN execution_attempts.execution_provider <> 'simulation'
                    THEN COALESCE(execution_attempts.actual_cost, 0)
                    ELSE 0
                END), 0) AS actual_provider_cost",
            )
            ->groupByRaw(self::CURRENCY_EXPRESSION)
            ->orderBy('currency')
            ->get();

        return array_values(
            $rows
                ->map(function (object $row): array {
                    $values = (array) $row;

                    return [
                        'currency' => (string) $values['currency'],
                        'attempts' => (int) $values['attempt_count'],
                        'executions' => (int) $values['execution_count'],
                        'estimatedSimulationCost' => $this->cost(
                            $values['estimated_simulation_cost'] ?? null,
                        ),
                        'estimatedProviderCost' => $this->cost(
                            $values['estimated_provider_cost'] ?? null,
                        ),
                        'actualProviderCost' => $this->cost(
                            $values['actual_provider_cost'] ?? null,
                        ),
                    ];
                })
                ->all(),
        );
    }

    /**
     * Return a reusable cost breakdown by one trusted internal dimension.
     *
     * Raw SQL expressions accepted here must only be hard-coded application
     * expressions and must never originate from request or external input.
     *
     * @param  literal-string  $dimensionExpression
     * @return list<array<string, int|string>>
     */
    private function breakdown(
        Builder $base,
        string $dimensionExpression,
        string $key,
    ): array {
        $rows = (clone $base)
            ->selectRaw($dimensionExpression.' AS dimension')
            ->selectRaw(
                self::CURRENCY_EXPRESSION.' AS currency',
            )
            ->selectRaw('COUNT(*) AS attempt_count')
            ->selectRaw(
                'COUNT(DISTINCT execution_attempts.execution_id) AS execution_count',
            )
            ->selectRaw(
                "COALESCE(SUM(CASE
                    WHEN execution_attempts.execution_provider = 'simulation'
                    THEN COALESCE(execution_attempts.estimated_cost, 0)
                    ELSE 0
                END), 0) AS estimated_simulation_cost",
            )
            ->selectRaw(
                "COALESCE(SUM(CASE
                    WHEN execution_attempts.execution_provider <> 'simulation'
                    THEN COALESCE(execution_attempts.estimated_cost, 0)
                    ELSE 0
                END), 0) AS estimated_provider_cost",
            )
            ->selectRaw(
                "COALESCE(SUM(CASE
                    WHEN execution_attempts.execution_provider <> 'simulation'
                    THEN COALESCE(execution_attempts.actual_cost, 0)
                    ELSE 0
                END), 0) AS actual_provider_cost",
            )
            ->groupByRaw($dimensionExpression)
            ->groupByRaw(self::CURRENCY_EXPRESSION)
            ->orderBy('dimension')
            ->orderBy('currency')
            ->get();

        return array_values(
            $rows
                ->map(function (object $row) use ($key): array {
                    $values = (array) $row;

                    return [
                        $key => (string) $values['dimension'],
                        'currency' => (string) $values['currency'],
                        'attempts' => (int) $values['attempt_count'],
                        'executions' => (int) $values['execution_count'],
                        'estimatedSimulationCost' => $this->cost(
                            $values['estimated_simulation_cost'] ?? null,
                        ),
                        'estimatedProviderCost' => $this->cost(
                            $values['estimated_provider_cost'] ?? null,
                        ),
                        'actualProviderCost' => $this->cost(
                            $values['actual_provider_cost'] ?? null,
                        ),
                    ];
                })
                ->all(),
        );
    }

    /**
     * Normalize a database decimal to the eight-place persisted cost format.
     */
    private function cost(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '0.00000000';
        }

        $value = trim((string) $value);

        if (preg_match('/^\d+(?:\.\d+)?$/D', $value) !== 1) {
            return number_format((float) $value, 8, '.', '');
        }

        [$whole, $fraction] = array_pad(
            explode('.', $value, 2),
            2,
            '',
        );

        return $whole.'.'.str_pad(
            substr($fraction, 0, 8),
            8,
            '0',
        );
    }
}
