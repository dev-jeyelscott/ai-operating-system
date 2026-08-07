<?php

declare(strict_types=1);

namespace App\Application\Simulation;

use App\Domain\Simulation\DeterministicScenario;
use InvalidArgumentException;

/**
 * Resolves release scenarios and produces seed-stable catalog selections.
 */
final class DeterministicScenarioCatalog
{
    public const int SCHEMA_VERSION = 1;

    /**
     * Return the complete release-blocking scenario catalog in stable order.
     *
     * @return list<DeterministicScenario>
     */
    public function all(): array
    {
        return DeterministicScenario::cases();
    }

    /**
     * Return every scenario as a reproducible selection for one seed.
     *
     * @return list<array{
     *     schema_version: int,
     *     scenario: string,
     *     seed: int,
     *     title: string,
     *     category: string,
     *     description: string,
     *     expected_result: string,
     *     provider_scenarios: array{
     *         planning: string|null,
     *         development: string|null,
     *         quality_assurance: string|null
     *     },
     *     tags: list<string>,
     *     fingerprint: string
     * }>
     */
    public function describeAll(int $seed = 1): array
    {
        return array_map(
            fn (DeterministicScenario $scenario): array => $this->select(
                $scenario->value,
                $seed,
            ),
            $this->all(),
        );
    }

    /**
     * Resolve one user-supplied scenario slug or fail closed.
     */
    public function resolve(string $scenario): DeterministicScenario
    {
        $normalized = trim($scenario);
        $resolved = DeterministicScenario::tryFrom($normalized);

        if ($resolved === null) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported deterministic scenario [%s]. Supported scenarios: %s.',
                $normalized,
                implode(', ', DeterministicScenario::values()),
            ));
        }

        return $resolved;
    }

    /**
     * Select one scenario and derive its canonical seed-specific fingerprint.
     *
     * @return array{
     *     schema_version: int,
     *     scenario: string,
     *     seed: int,
     *     title: string,
     *     category: string,
     *     description: string,
     *     expected_result: string,
     *     provider_scenarios: array{
     *         planning: string|null,
     *         development: string|null,
     *         quality_assurance: string|null
     *     },
     *     tags: list<string>,
     *     fingerprint: string
     * }
     */
    public function select(string $scenario, int $seed): array
    {
        if ($seed < 0) {
            throw new InvalidArgumentException(
                'The deterministic scenario seed must be zero or greater.',
            );
        }

        $resolved = $this->resolve($scenario);
        $payload = $this->payload($resolved, $seed);

        return [
            ...$payload,
            'fingerprint' => hash(
                'sha256',
                $this->canonicalJson($payload),
            ),
        ];
    }

    /**
     * Build the canonical selection payload without its derived fingerprint.
     *
     * @return array{
     *     schema_version: int,
     *     scenario: string,
     *     seed: int,
     *     title: string,
     *     category: string,
     *     description: string,
     *     expected_result: string,
     *     provider_scenarios: array{
     *         planning: string|null,
     *         development: string|null,
     *         quality_assurance: string|null
     *     },
     *     tags: list<string>
     * }
     */
    private function payload(
        DeterministicScenario $scenario,
        int $seed,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'scenario' => $scenario->value,
            'seed' => $seed,
            ...$scenario->definition(),
        ];
    }

    /**
     * Encode a recursively key-sorted value for stable hashing.
     */
    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Recursively sort associative keys while preserving list order.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = array_map(
            $this->canonicalize(...),
            $value,
        );

        if (! array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }
}
