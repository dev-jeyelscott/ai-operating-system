<?php

declare(strict_types=1);

namespace App\Domain\Projects;

/**
 * Defines the supported high-level project categories.
 */
enum ProjectType: string
{
    case WebApplication = 'web_app';
    case Api = 'api';
    case Library = 'library';
    case Service = 'service';
    case Mobile = 'mobile';
    case Other = 'other';

    /**
     * Return every persisted project type value.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            self::cases(),
        );
    }
}
