<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Events\DispatchOutboxMessages;
use Illuminate\Console\Command;

/**
 * Dispatches one bounded batch of committed outbox events.
 */
final class DispatchOutboxMessagesCommand extends Command
{
    /**
     * The console command name and options.
     *
     * @var string
     */
    protected $signature = 'outbox:dispatch
        {--limit= : Maximum number of outbox messages to claim}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description =
        'Dispatch committed domain events from the transactional outbox';

    /**
     * Execute one dispatcher batch.
     */
    public function handle(
        DispatchOutboxMessages $dispatcher,
    ): int {
        $configuredLimit = (int) config(
            'domain-events.dispatcher.batch_size',
            100,
        );

        $limit = $this->resolveLimit($configuredLimit);

        if ($limit === null) {
            $this->error(
                'The --limit option must be a positive integer.',
            );

            return self::FAILURE;
        }

        $result = $dispatcher->handle(
            limit: $limit,
            leaseSeconds: (int) config(
                'domain-events.dispatcher.lease_seconds',
                60,
            ),
            maximumAttempts: (int) config(
                'domain-events.dispatcher.maximum_attempts',
                10,
            ),
            baseBackoffSeconds: (int) config(
                'domain-events.dispatcher.base_backoff_seconds',
                5,
            ),
            maximumBackoffSeconds: (int) config(
                'domain-events.dispatcher.maximum_backoff_seconds',
                300,
            ),
        );

        $this->line(sprintf(
            'Claimed: %d; published: %d; failed: %d.',
            $result['claimed'],
            $result['published'],
            $result['failed'],
        ));

        return $result['failed'] === 0
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * Resolve and validate the optional batch-size override.
     */
    private function resolveLimit(
        int $configuredLimit,
    ): ?int {
        $option = $this->option('limit');

        if ($option === null) {
            return $configuredLimit > 0
                ? $configuredLimit
                : null;
        }

        $validated = filter_var(
            $option,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ],
        );

        return is_int($validated)
            ? $validated
            : null;
    }
}
