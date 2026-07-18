<?php

declare(strict_types=1);

namespace App\Domain\Projects\Configuration;

use App\Domain\Projects\Configuration\Exceptions\UnsupportedProjectConfigurationSchemaVersion;

/**
 * Defines the stable, versioned project configuration contract.
 *
 * schema_version changes only when the serialized shape changes.
 * revision changes when a project's values materially change.
 */
final class ProjectConfigurationSchema
{
    public const CURRENT_VERSION = 1;

    public const INITIAL_REVISION = 1;

    /**
     * Assumption for AIOS-021:
     * automatic retries are bounded to prevent runaway execution.
     *
     * Change this constant and the matching migration constraint together if
     * the approved product policy selects another upper limit.
     */
    public const MAX_AUTOMATIC_RETRY_LIMIT = 10;

    /**
     * JSON strings are used by Eloquent as model-level default attributes.
     */
    public const TECHNOLOGY_STACK_DEFAULT_JSON = <<<'JSON'
{"languages":[],"frameworks":[],"databases":[],"infrastructure":[],"package_managers":[],"runtimes":[]}
JSON;

    public const PROVIDER_POLICY_DEFAULT_JSON = <<<'JSON'
{"allowed_provider_ids":[],"fallback_order":[]}
JSON;

    public const APPROVAL_POLICY_DEFAULT_JSON = <<<'JSON'
{"roadmap_required":true,"ticket_execution_required":true,"merge_required":true}
JSON;

    public const NOTIFICATION_POLICY_DEFAULT_JSON = <<<'JSON'
{"channels":["in_app"],"events":[]}
JSON;

    /**
     * Determine whether this application release can read the given schema.
     */
    public static function supports(int $version): bool
    {
        return $version === self::CURRENT_VERSION;
    }

    /**
     * Stop processing instead of guessing when a newer schema is encountered.
     */
    public static function assertSupported(int $version): void
    {
        if (! self::supports($version)) {
            throw UnsupportedProjectConfigurationSchemaVersion::forVersion(
                $version,
            );
        }
    }

    /**
     * Return the empty technology-stack object for schema version 1.
     *
     * @return array<string, array<int, string>>
     */
    public static function technologyStackDefaults(): array
    {
        return [
            'languages' => [],
            'frameworks' => [],
            'databases' => [],
            'infrastructure' => [],
            'package_managers' => [],
            'runtimes' => [],
        ];
    }

    /**
     * Return the provider-routing defaults for schema version 1.
     *
     * @return array<string, array<int, string>>
     */
    public static function providerPolicyDefaults(): array
    {
        return [
            'allowed_provider_ids' => [],
            'fallback_order' => [],
        ];
    }

    /**
     * Return conservative human-approval defaults for consequential actions.
     *
     * @return array<string, bool>
     */
    public static function approvalPolicyDefaults(): array
    {
        return [
            'roadmap_required' => true,
            'ticket_execution_required' => true,
            'merge_required' => true,
        ];
    }

    /**
     * Return safe notification defaults using the currently supported channel.
     *
     * @return array<string, array<int, string>>
     */
    public static function notificationPolicyDefaults(): array
    {
        return [
            'channels' => ['in_app'],
            'events' => [],
        ];
    }
}
