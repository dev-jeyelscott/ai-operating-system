<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Simulation\DeterministicScenarioCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Lists or describes one deterministic release scenario.
 */
#[Signature(
    'simulation:scenarios
        {scenario? : Stable scenario slug to inspect}
        {--seed=1 : Non-negative deterministic seed}
        {--json : Print machine-readable JSON}',
)]
#[Description(
    'List or inspect the complete deterministic MVP scenario catalog.',
)]
final class ListDeterministicScenariosCommand extends Command
{
    /**
     * Inject the canonical deterministic scenario catalog.
     */
    public function __construct(
        private readonly DeterministicScenarioCatalog $catalog,
    ) {
        parent::__construct();
    }

    /**
     * Validate the input and print either the complete catalog or one selection.
     */
    public function handle(): int
    {
        $seed = filter_var(
            $this->option('seed'),
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 0,
                ],
            ],
        );

        if (! is_int($seed)) {
            $this->components->error(
                'The seed option must be a non-negative integer.',
            );

            return self::INVALID;
        }

        $argument = $this->argument('scenario');

        if ($argument !== null && ! is_string($argument)) {
            $this->components->error(
                'The scenario argument must be a string.',
            );

            return self::INVALID;
        }

        try {
            if (is_string($argument) && trim($argument) !== '') {
                return $this->renderSelection(
                    scenario: $argument,
                    seed: $seed,
                );
            }

            return $this->renderCatalog($seed);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::INVALID;
        }
    }

    /**
     * Render one seed-specific scenario selection.
     */
    private function renderSelection(
        string $scenario,
        int $seed,
    ): int {
        $selection = $this->catalog->select($scenario, $seed);

        if ($this->option('json')) {
            $this->line($this->encode($selection));

            return self::SUCCESS;
        }

        $providerScenarios = $selection['provider_scenarios'];

        $this->table(
            ['Field', 'Value'],
            [
                ['Scenario', $selection['scenario']],
                ['Title', $selection['title']],
                ['Category', $selection['category']],
                ['Seed', (string) $selection['seed']],
                ['Planning scenario', $providerScenarios['planning'] ?? 'Not applicable'],
                ['Development scenario', $providerScenarios['development'] ?? 'Not applicable'],
                ['QA scenario', $providerScenarios['quality_assurance'] ?? 'Not applicable'],
                ['Expected result', $selection['expected_result']],
                ['Fingerprint', $selection['fingerprint']],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Render the complete 14-scenario release catalog.
     */
    private function renderCatalog(int $seed): int
    {
        $scenarios = $this->catalog->describeAll($seed);

        if ($this->option('json')) {
            $this->line($this->encode([
                'schema_version' => DeterministicScenarioCatalog::SCHEMA_VERSION,
                'scenario_count' => count($scenarios),
                'seed' => $seed,
                'scenarios' => $scenarios,
            ]));

            return self::SUCCESS;
        }

        $this->table(
            ['Scenario', 'Category', 'Expected result'],
            array_map(
                static fn (array $scenario): array => [
                    $scenario['scenario'],
                    $scenario['category'],
                    $scenario['expected_result'],
                ],
                $scenarios,
            ),
        );

        return self::SUCCESS;
    }

    /**
     * Encode command output as deterministic, readable JSON.
     *
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR
                | JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        );
    }
}
