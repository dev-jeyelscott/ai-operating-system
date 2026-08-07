<?php

declare(strict_types=1);

use App\Domain\Projects\Configuration\AutonomyLevel;
use App\Domain\Projects\Configuration\CodexProviderPolicy;
use App\Domain\Projects\Configuration\ProjectPolicyConfiguration;
use App\Domain\Projects\Configuration\ReasoningLevel;

test(
    'project policy configuration is normalized for persistence',
    function (): void {
        $policy = ProjectPolicyConfiguration::fromValidatedPayload([
            'default_reasoning' => ' HIGH ',
            'provider_policy' => [
                'allowed_provider_ids' => [
                    'Simulation',
                    'OPENAI',
                ],
                'fallback_order' => [
                    'OPENAI',
                    'Simulation',
                ],
            ],
            'budget_limit_minor' => '12500',
            'budget_currency' => ' usd ',
            'automatic_retry_limit' => '2',
            'autonomy_level' => ' APPROVAL_REQUIRED ',
            'approval_policy' => [
                'roadmap_required' => true,
                'ticket_execution_required' => true,
                'merge_required' => true,
            ],
            'notification_policy' => [
                'channels' => ['in_app'],
                'events' => [
                    'roadmap.ready',
                    'approval.requested',
                ],
            ],
        ]);

        /*
         * Build the expected Codex value through the same domain normalizer
         * used by ProviderPolicy so capability ordering is canonical.
         */
        $expectedCodexPolicy = CodexProviderPolicy::fromArray(
            CodexProviderPolicy::defaults(),
        )->toArray();

        $attributes = $policy->toPersistenceAttributes();

        expect($attributes['default_reasoning'])
            ->toBe(ReasoningLevel::High)
            ->and($attributes['provider_policy'])
            ->toBe([
                'allowed_provider_ids' => [
                    'openai',
                    'simulation',
                ],
                'fallback_order' => [
                    'openai',
                    'simulation',
                ],
                'codex' => $expectedCodexPolicy,
            ])
            ->and($attributes['budget_limit_minor'])
            ->toBe(12500)
            ->and($attributes['budget_currency'])
            ->toBe('USD')
            ->and($attributes['automatic_retry_limit'])
            ->toBe(2)
            ->and($attributes['autonomy_level'])
            ->toBe(AutonomyLevel::ApprovalRequired)
            ->and($attributes['approval_policy'])
            ->toBe([
                'roadmap_required' => true,
                'ticket_execution_required' => true,
                'merge_required' => true,
            ])
            ->and($attributes['notification_policy'])
            ->toBe([
                'channels' => ['in_app'],
                'events' => [
                    'approval.requested',
                    'roadmap.ready',
                ],
            ]);
    },
);

test(
    'provider fallback cannot reference a provider outside the allowlist',
    function (): void {
        expect(
            fn (): ProjectPolicyConfiguration => ProjectPolicyConfiguration::fromValidatedPayload(
                validUnitProjectPolicyPayload([
                    'provider_policy' => [
                        'allowed_provider_ids' => ['simulation'],
                        'fallback_order' => ['openai'],
                    ],
                ]),
            ),
        )->toThrow(InvalidArgumentException::class);
    },
);

test(
    'approval fields must remain explicit booleans',
    function (): void {
        expect(
            fn (): ProjectPolicyConfiguration => ProjectPolicyConfiguration::fromValidatedPayload(
                validUnitProjectPolicyPayload([
                    'approval_policy' => [
                        'roadmap_required' => 'yes',
                        'ticket_execution_required' => true,
                        'merge_required' => true,
                    ],
                ]),
            ),
        )->toThrow(InvalidArgumentException::class);
    },
);

test(
    'retry and budget values must stay within configured bounds',
    function (string $field, mixed $value): void {
        expect(
            fn (): ProjectPolicyConfiguration => ProjectPolicyConfiguration::fromValidatedPayload(
                validUnitProjectPolicyPayload([
                    $field => $value,
                ]),
            ),
        )->toThrow(InvalidArgumentException::class);
    },
)->with([
    'negative budget' => [
        'budget_limit_minor',
        -1,
    ],
    'oversized budget' => [
        'budget_limit_minor',
        ProjectPolicyConfiguration::MAX_BUDGET_LIMIT_MINOR + 1,
    ],
    'negative retries' => [
        'automatic_retry_limit',
        -1,
    ],
    'too many retries' => [
        'automatic_retry_limit',
        ProjectPolicyConfiguration::MAX_AUTOMATIC_RETRY_LIMIT + 1,
    ],
]);

/**
 * Return a complete valid unit-test policy payload.
 *
 * Codex is intentionally omitted so this fixture also verifies that the
 * provider-policy domain supplies the secure disabled Codex defaults.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validUnitProjectPolicyPayload(array $overrides = []): array
{
    return array_replace([
        'default_reasoning' => 'medium',
        'provider_policy' => [
            'allowed_provider_ids' => ['simulation'],
            'fallback_order' => ['simulation'],
        ],
        'budget_limit_minor' => 10000,
        'budget_currency' => 'USD',
        'automatic_retry_limit' => 2,
        'autonomy_level' => 'approval_required',
        'approval_policy' => [
            'roadmap_required' => true,
            'ticket_execution_required' => true,
            'merge_required' => true,
        ],
        'notification_policy' => [
            'channels' => ['in_app'],
            'events' => ['approval.requested'],
        ],
    ], $overrides);
}
