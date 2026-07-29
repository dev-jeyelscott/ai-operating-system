<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance\Data;

use App\Domain\QualityAssurance\QaFindingSeverity;
use App\Domain\QualityAssurance\QaReviewDimension;

/**
 * Represents one unresolved, evidence-referenced QA finding.
 */
final readonly class QaFinding
{
    /**
     * @param  list<string>  $evidenceIds
     */
    public function __construct(
        public string $code,
        public QaReviewDimension $dimension,
        public QaFindingSeverity $severity,
        public bool $blocking,
        public string $summary,
        public string $impact,
        public string $mitigation,
        public array $evidenceIds,
    ) {}

    /**
     * Convert the finding to its canonical transport representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'dimension' => $this->dimension->value,
            'severity' => $this->severity->value,
            'blocking' => $this->blocking,
            'summary' => $this->summary,
            'impact' => $this->impact,
            'mitigation' => $this->mitigation,
            'evidence_ids' => $this->evidenceIds,
        ];
    }

    /**
     * Build a finding from a strictly typed transport payload.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            code: self::string($data, 'code'),
            dimension: QaReviewDimension::from(
                self::string($data, 'dimension'),
            ),
            severity: QaFindingSeverity::from(
                self::string($data, 'severity'),
            ),
            blocking: self::boolean($data, 'blocking'),
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
                "QA finding {$key} field is malformed.",
            );
        }

        return $data[$key];
    }

    /**
     * Read one required boolean without accepting integer or string substitutes.
     *
     * @param  array<string, mixed>  $data
     */
    private static function boolean(array $data, string $key): bool
    {
        if (
            ! array_key_exists($key, $data)
            || ! is_bool($data[$key])
        ) {
            throw new \InvalidArgumentException(
                "QA finding {$key} field is malformed.",
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
                'QA finding evidence list is malformed.',
            );
        }

        return $value;
    }
}
