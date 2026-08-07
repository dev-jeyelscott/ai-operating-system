<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Executions\ExecutionAttemptStatus;
use App\Jobs\EvaluateCodexLivenessJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Dispatches bounded liveness evaluation for suspicious Codex attempts.
 */
final class DispatchCodexLivenessChecks extends Command
{
    protected $signature = 'codex:dispatch-liveness-checks
        {--limit=200 : Maximum attempts to inspect in one dispatcher pass}';

    protected $description = 'Dispatch bounded Codex liveness and recovery evaluation';

    /**
     * Find suspicious Codex attempts and enqueue deduplicated recovery jobs.
     */
    public function handle(): int
    {
        $limit = (int) $this->option(
            'limit',
        );

        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException(
                'Codex liveness batch size must be between 1 and 1000.',
            );
        }

        $now = CarbonImmutable::now();

        $staleHeartbeatSeconds = config(
            'codex-app-server.persistence.stale_heartbeat_seconds',
            45,
        );

        if (
            ! is_int($staleHeartbeatSeconds)
            || $staleHeartbeatSeconds < 1
        ) {
            throw new InvalidArgumentException(
                'Codex stale heartbeat configuration is invalid.',
            );
        }

        $staleAt = $now->subSeconds(
            $staleHeartbeatSeconds,
        );

        /** @var list<int> $attemptIds */
        $attemptIds = DB::table(
            'execution_attempts',
        )
            ->join(
                'executions',
                'executions.id',
                '=',
                'execution_attempts.execution_id',
            )
            ->leftJoin(
                'provider_sessions',
                'provider_sessions.execution_attempt_id',
                '=',
                'execution_attempts.id',
            )
            ->where(
                'execution_attempts.execution_provider',
                'codex',
            )
            ->where(
                'execution_attempts.status',
                ExecutionAttemptStatus::Running->value,
            )
            ->where(
                function (Builder $query) use (
                    $now,
                    $staleAt,
                ): void {
                    $query
                        ->whereNotNull(
                            'executions.cancel_requested_at',
                        )
                        ->orWhere(
                            'execution_attempts.deadline_at',
                            '<=',
                            $now,
                        )
                        ->orWhere(
                            'execution_attempts.heartbeat_at',
                            '<=',
                            $staleAt,
                        )
                        ->orWhere(
                            'provider_sessions.phase_deadline_at',
                            '<=',
                            $now,
                        )
                        ->orWhereNotNull(
                            'provider_sessions.recovery_required_at',
                        )
                        ->orWhere(
                            function (Builder $terminal): void {
                                $terminal
                                    ->whereNotNull(
                                        'provider_sessions.id',
                                    )
                                    ->where(
                                        'provider_sessions.status',
                                        '!=',
                                        'active',
                                    );
                            },
                        );
                },
            )
            ->orderBy(
                'execution_attempts.id',
            )
            ->limit($limit)
            ->pluck(
                'execution_attempts.id',
            )
            ->map(
                static fn (mixed $value): int => (int) $value,
            )
            ->all();

        foreach ($attemptIds as $attemptId) {
            EvaluateCodexLivenessJob::dispatch(
                $attemptId,
            );
        }

        $this->info(sprintf(
            'Dispatched %d Codex liveness check(s).',
            count($attemptIds),
        ));

        return self::SUCCESS;
    }
}
