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
    public const VERSION_1 = 1;

    public const VERSION_2 = 2;

    public const VERSION_3 = 3;

    public const CURRENT_VERSION = self::VERSION_3;

    public const INITIAL_REVISION = 1;

    public const MAX_AUTOMATIC_RETRY_LIMIT = 10;

    public const TECHNOLOGY_STACK_DEFAULT_JSON = <<<'JSON'
{"languages":[],"frameworks":[],"databases":[],"infrastructure":[],"package_managers":[],"runtimes":[]}
JSON;

    public const PROVIDER_POLICY_DEFAULT_JSON = <<<'JSON'
{"allowed_provider_ids":[],"fallback_order":[],"codex":{"enabled":false,"model_identifier":"gpt-5.3-codex","allowed_capabilities":["planning.generate","development.execute","quality_assurance.review"],"reasoning":{"minimum":"medium","maximum":"high"},"sandbox":{"planning":"read-only","development":"workspace-write","quality_assurance":"read-only"},"network":{"default":"deny","allow_escalation_with_approval":true},"budget_limit_minor":null,"timeout_seconds":900,"retry_limit":2}}
JSON;

    public const APPROVAL_POLICY_DEFAULT_JSON = <<<'JSON'
{"roadmap_required":true,"ticket_execution_required":true,"merge_required":true}
JSON;

    public const NOTIFICATION_POLICY_DEFAULT_JSON = <<<'JSON'
{"channels":["in_app"],"events":[]}
JSON;

    /**
     * Determine whether this application release recognizes the given schema.
     */
    public static function supports(int $version): bool
    {
        return in_array(
            $version,
            [self::VERSION_1, self::VERSION_2, self::VERSION_3],
            true,
        );
    }

    /**
     * Stop processing instead of guessing when a newer schema is encountered.
     */
    public static function assertSupported(int $version): void
    {
        if (! self::supports($version)) {
            throw UnsupportedProjectConfigurationSchemaVersion::forVersion($version);
        }
    }

    /**
     * Return the empty technology-stack object.
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
     * Return fail-closed provider-routing defaults for the current schema.
     *
     * @return array<string, mixed>
     */
    public static function providerPolicyDefaults(): array
    {
        return [
            'allowed_provider_ids' => [],
            'fallback_order' => [],
            'codex' => CodexProviderPolicy::defaults(),
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
