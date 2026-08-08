<?php

declare(strict_types=1);

namespace App\Application\Planning\Context;

use JsonException;

/**
 * Produces stable JSON bytes for immutable planning fingerprints.
 */
final readonly class CanonicalJson
{
    /**
     * Encode one value after recursively sorting map keys.
     *
     * @throws JsonException
     */
    public function encode(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION,
            512,
        );
    }

    /**
     * Return the SHA-256 fingerprint of the canonical bytes.
     *
     * @throws JsonException
     */
    public function fingerprint(mixed $value): string
    {
        return hash('sha256', $this->encode($value));
    }

    /**
     * Sort associative keys recursively while preserving list order.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalize($item),
                $value,
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
