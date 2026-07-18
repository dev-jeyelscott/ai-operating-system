<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Defines the organization-scoped roles supported by the application.
 *
 * These roles are intentionally separate from global application roles.
 * A user may have a different role in each organization.
 */
enum OrganizationRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Member = 'member';
    case Viewer = 'viewer';

    /**
     * Return every persisted role value.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $role): string => $role->value,
            self::cases(),
        );
    }
}
