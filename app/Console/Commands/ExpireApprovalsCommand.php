<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Approvals\ExpireDueApprovals;
use Illuminate\Console\Command;

/**
 * Expires due approval requests through the deterministic approval engine.
 */
final class ExpireApprovalsCommand extends Command
{
    /**
     * Define the command name and bounded batch option.
     *
     * @var string
     */
    protected $signature =
        'approvals:expire
        {--limit=500 : Maximum number of approvals to expire}';

    /**
     * Explain the command in Artisan listings.
     *
     * @var string
     */
    protected $description =
        'Expire pending approval requests that reached their deadline';

    /**
     * Inject the approval expiration application service.
     */
    public function __construct(
        private readonly ExpireDueApprovals $expirer,
    ) {
        parent::__construct();
    }

    /**
     * Expire one bounded batch and report the result.
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

        $expiredCount = $this->expirer->handle(
            limit: $limit,
        );

        $this->info(sprintf(
            'Expired %d approval request(s).',
            $expiredCount,
        ));

        return self::SUCCESS;
    }
}
