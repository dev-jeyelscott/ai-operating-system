<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Executions\ExecutionResilienceManager;
use App\Application\Tickets\TicketLeaseManager;
use Illuminate\Console\Command;

/**
 * Processes timed-out attempts and releases due execution retries.
 */
final class ProcessExecutionResilienceCommand extends Command
{
    /**
     * Define the command and bounded batch option.
     *
     * @var string
     */
    protected $signature =
        'executions:recover
        {--limit=200 : Maximum records processed by each recovery scan}';

    /**
     * Explain the command in Artisan listings.
     *
     * @var string
     */
    protected $description =
        'Process execution timeouts and release due retries';

    /**
     * Inject the deterministic resilience manager.
     */
    public function __construct(
        private readonly ExecutionResilienceManager $manager,
        private readonly TicketLeaseManager $leases,
    ) {
        parent::__construct();
    }

    /**
     * Process one bounded resilience-recovery pass.
     */
    public function handle(): int
    {
        $limit = filter_var(
            $this->option('limit'),
            FILTER_VALIDATE_INT,
        );

        if (
            ! is_int($limit)
            || $limit < 1
            || $limit > 1_000
        ) {
            $this->error(
                'The --limit option must be between 1 and 1,000.',
            );

            return self::INVALID;
        }

        $timedOutCount =
            $this->manager->processExpiredAttempts(
                limit: $limit,
            );

        $releasedRetryCount =
            $this->manager->releaseDueRetries(
                limit: $limit,
            );

        $recoveredLeaseCount = $this->leases->recoverExpired(
            limit: $limit,
        );

        $this->info(sprintf(
            'Timed out %d attempt(s); released %d retry execution(s); recovered %d ticket lease(s).',
            $timedOutCount,
            $releasedRetryCount,
            $recoveredLeaseCount,
        ));

        return self::SUCCESS;
    }
}
