<?php

declare(strict_types=1);

namespace App\Application\Planning;

final class RoadmapCommandFingerprint
{
    public static function make(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonicalize($value), JSON_THROW_ON_ERROR));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = array_map(self::canonicalize(...), $value);
        if (! array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }
}
