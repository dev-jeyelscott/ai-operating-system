<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Operations\RebuildOfficeProjections;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Rebuilds persisted office projections after deployment or recovery.
 */
final class RebuildOfficeProjectionsCommand extends Command
{
    /**
     * Define the command name and supported filters.
     *
     * @var string
     */
    protected $signature = 'office:projections:rebuild
        {--organization= : Restrict the rebuild to one organization ID}
        {--project= : Restrict the rebuild to one project ID}
        {--chunk=100 : Number of projects processed per database chunk}';

    /**
     * Describe the command for the Artisan command list.
     *
     * @var string
     */
    protected $description =
        'Rebuild office projections from durable project state and event checkpoints';

    /**
     * Execute one bounded projection rebuild run.
     */
    public function handle(
        RebuildOfficeProjections $rebuild,
    ): int {
        try {
            $organizationId = $this->positiveIntegerOption(
                'organization',
                optional: true,
            );
            $projectId = $this->positiveIntegerOption(
                'project',
                optional: true,
            );
            $chunkSize = $this->positiveIntegerOption(
                'chunk',
                optional: false,
            );

            if ($chunkSize === null) {
                throw new InvalidArgumentException(
                    'The --chunk option must be a positive integer.',
                );
            }
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $result = $rebuild->handle(
            organizationId: $organizationId,
            projectId: $projectId,
            chunkSize: $chunkSize,
        );

        $this->line(sprintf(
            'Office projections rebuilt: %d; failed: %d.',
            $result['processed'],
            $result['failed'],
        ));

        foreach ($result['failures'] as $failure) {
            $this->warn(sprintf(
                'Organization %d, project %d failed with %s.',
                $failure['organization_id'],
                $failure['project_id'],
                $failure['exception'],
            ));
        }

        return $result['failed'] === 0
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * Parse one positive integer option without accepting partial numeric input.
     */
    private function positiveIntegerOption(
        string $name,
        bool $optional,
    ): ?int {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            if ($optional) {
                return null;
            }

            throw new InvalidArgumentException(
                "The --{$name} option must be a positive integer.",
            );
        }

        if (! is_scalar($value)) {
            throw new InvalidArgumentException(
                "The --{$name} option must be a positive integer.",
            );
        }

        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ],
        );

        if (! is_int($validated)) {
            throw new InvalidArgumentException(
                "The --{$name} option must be a positive integer.",
            );
        }

        return $validated;
    }
}
