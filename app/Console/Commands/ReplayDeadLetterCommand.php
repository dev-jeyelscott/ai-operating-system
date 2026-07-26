<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Events\DeadLetterManager;
use App\Domain\Events\DeadLetterSource;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Replays exactly one allowlisted dead letter after explicit operator intent.
 */
final class ReplayDeadLetterCommand extends Command
{
    /**
     * Require source, durable identifier, operator identity, and rationale.
     *
     * @var string
     */
    protected $signature = 'dead-letters:replay
        {source : outbox or queue}
        {id : Outbox event ID or failed-job UUID}
        {--actor= : Stable operator identifier for the audit trail}
        {--reason= : Required replay rationale}
        {--yes : Skip interactive confirmation}';

    /**
     * Describe the privileged recovery operation.
     *
     * @var string
     */
    protected $description =
        'Safely replay one outbox or allowlisted queue dead letter';

    /**
     * Validate operator intent and execute one replay.
     */
    public function handle(DeadLetterManager $manager): int
    {
        try {
            $source = DeadLetterSource::tryFrom(
                strtolower(trim((string) $this->argument('source'))),
            );

            if ($source === null) {
                throw new InvalidArgumentException(
                    'The source must be outbox or queue.',
                );
            }

            $identifier = trim(
                (string) $this->argument('id'),
            );
            $actorId = trim(
                (string) $this->option('actor'),
            );
            $reason = trim(
                (string) $this->option('reason'),
            );

            if (
                ! (bool) $this->option('yes')
                && ! $this->confirm(
                    sprintf(
                        'Replay %s dead letter [%s]?',
                        $source->value,
                        $identifier,
                    ),
                )
            ) {
                $this->warn('Replay cancelled.');

                return self::SUCCESS;
            }

            $record = $manager->replay(
                source: $source,
                identifier: $identifier,
                actorId: $actorId,
                reason: $reason,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Replay requested for %s dead letter [%s], event [%s].',
            $record->source->value,
            $record->id,
            $record->eventId,
        ));

        return self::SUCCESS;
    }
}
