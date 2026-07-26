<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Events\Data\DeadLetterRecord;
use App\Application\Events\DeadLetterManager;
use App\Domain\Events\DeadLetterSource;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Displays safe dead-letter summaries without exposing stored payloads.
 */
final class ListDeadLettersCommand extends Command
{
    /**
     * Define operator filters for source and result size.
     *
     * @var string
     */
    protected $signature = 'dead-letters:list
        {--source=all : all, outbox, or queue}
        {--limit=50 : Maximum records to display}';

    /**
     * Describe the operator command.
     *
     * @var string
     */
    protected $description =
        'List outbox dead letters and allowlisted failed consumer jobs';

    /**
     * Display the newest matching dead letters.
     */
    public function handle(DeadLetterManager $manager): int
    {
        try {
            $source = $this->resolveSource();
            $limit = $this->resolveLimit();

            if ($limit === null) {
                throw new InvalidArgumentException(
                    'The --limit option must be an integer between 1 and 100.',
                );
            }

            $records = $manager->inspect(
                source: $source,
                limit: $limit,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($records === []) {
            $this->info('No safe dead letters were found.');

            return self::SUCCESS;
        }

        $this->table(
            [
                'Source',
                'ID',
                'Event ID',
                'Event',
                'Attempts',
                'Failed at',
                'Error type',
            ],
            array_map(
                static fn (
                    DeadLetterRecord $record,
                ): array => $record->toTableRow(),
                $records,
            ),
        );

        $this->newLine();
        $this->comment(
            'Queue results include only failed ConsumeOutboxMessage jobs. '
                .'Arbitrary failed jobs are not eligible for this control.',
        );

        return self::SUCCESS;
    }

    /**
     * Convert the source option into a domain enum.
     */
    private function resolveSource(): ?DeadLetterSource
    {
        $value = strtolower(
            trim((string) $this->option('source')),
        );

        if ($value === 'all') {
            return null;
        }

        $source = DeadLetterSource::tryFrom($value);

        if ($source === null) {
            throw new InvalidArgumentException(
                'The --source option must be all, outbox, or queue.',
            );
        }

        return $source;
    }

    /**
     * Validate the requested result limit.
     */
    private function resolveLimit(): ?int
    {
        $validated = filter_var(
            $this->option('limit'),
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 100,
                ],
            ],
        );

        return is_int($validated)
            ? $validated
            : null;
    }
}
