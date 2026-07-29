<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance\Data;

use App\Domain\QualityAssurance\QaImpactLevel;

/**
 * Represents one residual merge risk and its proposed mitigation.
 */
final readonly class MergeRisk
{
    /**
     * @param  list<string>  $evidenceIds
     */
    public function __construct(
        public string $code,
        public QaImpactLevel $level,
        public string $summary,
        public string $impact,
        public string $mitigation,
        public array $evidenceIds,
    ) {}

    /**
     * Convert the risk to its canonical transport representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'level' => $this->level->value,
            'summary' => $this->summary,
            'impact' => $this->impact,
            'mitigation' => $this->mitigation,
            'evidence_ids' => $this->evidenceIds,
        ];
    }

    /**
     * Build a merge risk from a strictly typed transport payload.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            code: self::string($data, 'code'),
            level: QaImpactLevel::from(
                self::string($data, 'level'),
            ),
            summary: self::string($data, 'summary'),
            impact: self::string($data, 'impact'),
            mitigation: self::string($data, 'mitigation'),
            evidenceIds: self::strings($data['evidence_ids'] ?? null),
        );
    }

    /**
     * Read one required string without silently coercing malformed values.
     *
     * @param  array<string, mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        if (
            ! array_key_exists($key, $data)
            || ! is_string($data[$key])
        ) {
            throw new \InvalidArgumentException(
                "Merge risk {$key} field is malformed.",
            );
        }

        return $data[$key];
    }

    /**
     * Read one required list of strings without discarding malformed entries.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (
            ! is_array($value)
            || ! array_is_list($value)
            || array_any(
                $value,
                static fn (mixed $item): bool => ! is_string($item),
            )
        ) {
            throw new \InvalidArgumentException(
                'Merge risk evidence list is malformed.',
            );
        }

        return $value;
    }
}
