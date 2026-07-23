<?php

declare(strict_types=1);

namespace App\Domain\Projects\Configuration;

use InvalidArgumentException;

/**
 * Represents the canonical provider allowlist and ordered fallback strategy.
 *
 * Allowed provider IDs are treated as a set and are therefore sorted before
 * persistence. Fallback order is intentionally preserved because its ordering
 * changes provider-routing behavior.
 */
final readonly class ProviderPolicy
{
    public const int MAX_PROVIDERS = 20;

    public const int MAX_PROVIDER_ID_LENGTH = 100;

    private const string PROVIDER_ID_PATTERN =
        '/\A[a-z][a-z0-9._-]{0,99}\z/D';

    /**
     * @param  list<string>  $allowedProviderIds
     * @param  list<string>  $fallbackOrder
     */
    private function __construct(
        public array $allowedProviderIds,
        public array $fallbackOrder,
    ) {}

    /**
     * Build a canonical provider policy from validated project input.
     *
     * @param  array<string, mixed>  $policy
     */
    public static function fromArray(array $policy): self
    {
        $allowedProviderIds = self::normalizeProviderIds(
            value: $policy['allowed_provider_ids'] ?? null,
            label: 'allowed provider IDs',
        );

        $fallbackOrder = self::normalizeProviderIds(
            value: $policy['fallback_order'] ?? null,
            label: 'provider fallback order',
        );

        if ($allowedProviderIds === []) {
            throw new InvalidArgumentException(
                'At least one provider must be allowed.',
            );
        }

        if ($fallbackOrder === []) {
            throw new InvalidArgumentException(
                'At least one provider must be present in the fallback order.',
            );
        }

        /*
         * The allowlist is set-like, so canonical ordering prevents harmless
         * input-order changes from creating a new configuration revision.
         */
        sort($allowedProviderIds, SORT_STRING);

        $allowedProviderLookup = array_fill_keys(
            $allowedProviderIds,
            true,
        );

        foreach ($fallbackOrder as $providerId) {
            if (! isset($allowedProviderLookup[$providerId])) {
                throw new InvalidArgumentException(sprintf(
                    'Fallback provider [%s] is not in the provider allowlist.',
                    $providerId,
                ));
            }
        }

        return new self(
            allowedProviderIds: $allowedProviderIds,
            fallbackOrder: $fallbackOrder,
        );
    }

    /**
     * Return the JSON-compatible persistence representation.
     *
     * @return array{
     *     allowed_provider_ids: list<string>,
     *     fallback_order: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'allowed_provider_ids' => $this->allowedProviderIds,
            'fallback_order' => $this->fallbackOrder,
        ];
    }

    /**
     * Normalize and validate one ordered provider-ID collection.
     *
     * @return list<string>
     */
    private static function normalizeProviderIds(
        mixed $value,
        string $label,
    ): array {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf(
                'The %s must be a list.',
                $label,
            ));
        }

        if (count($value) > self::MAX_PROVIDERS) {
            throw new InvalidArgumentException(sprintf(
                'The %s may contain at most %d providers.',
                $label,
                self::MAX_PROVIDERS,
            ));
        }

        $normalizedProviderIds = [];
        $seenProviderIds = [];

        foreach ($value as $providerId) {
            if (! is_string($providerId)) {
                throw new InvalidArgumentException(sprintf(
                    'Every entry in the %s must be a string.',
                    $label,
                ));
            }

            $normalizedProviderId = strtolower(trim($providerId));

            if (
                preg_match(
                    self::PROVIDER_ID_PATTERN,
                    $normalizedProviderId,
                ) !== 1
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Provider ID [%s] has an invalid format.',
                    $normalizedProviderId,
                ));
            }

            if (isset($seenProviderIds[$normalizedProviderId])) {
                throw new InvalidArgumentException(sprintf(
                    'Provider ID [%s] is duplicated in the %s.',
                    $normalizedProviderId,
                    $label,
                ));
            }

            $seenProviderIds[$normalizedProviderId] = true;
            $normalizedProviderIds[] = $normalizedProviderId;
        }

        return $normalizedProviderIds;
    }
}
