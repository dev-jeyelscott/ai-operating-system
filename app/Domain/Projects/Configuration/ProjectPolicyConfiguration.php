<?php

declare(strict_types=1);

namespace App\Domain\Projects\Configuration;

use InvalidArgumentException;

/**
 * Canonical project execution-policy configuration.
 *
 * This object validates and normalizes persisted policy values. It does not
 * decide whether a workflow may execute; runtime policy resolution belongs to
 * the later policy-decision and reasoning-resolver implementation.
 */
final readonly class ProjectPolicyConfiguration
{
    public const int MAX_BUDGET_LIMIT_MINOR = 999_999_999_999;

    public const int MAX_AUTOMATIC_RETRY_LIMIT = 10;

    public const int MAX_NOTIFICATION_EVENTS = 30;

    public const int MAX_NOTIFICATION_EVENT_LENGTH = 100;

    /**
     * @param  array{
     *     roadmap_required: bool,
     *     ticket_execution_required: bool,
     *     merge_required: bool
     * }  $approvalPolicy
     * @param  array{
     *     channels: list<string>,
     *     events: list<string>
     * }  $notificationPolicy
     */
    private function __construct(
        public ReasoningLevel $defaultReasoning,
        public ProviderPolicy $providerPolicy,
        public ?int $budgetLimitMinor,
        public string $budgetCurrency,
        public int $automaticRetryLimit,
        public AutonomyLevel $autonomyLevel,
        public array $approvalPolicy,
        public array $notificationPolicy,
    ) {}

    /**
     * Build a canonical policy from validated setup-step data.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromValidatedPayload(array $payload): self
    {
        return new self(
            defaultReasoning: self::reasoningLevel(
                $payload['default_reasoning'] ?? null,
            ),
            providerPolicy: self::providerPolicy(
                $payload['provider_policy'] ?? null,
            ),
            budgetLimitMinor: self::budgetLimitMinor(
                $payload['budget_limit_minor'] ?? null,
            ),
            budgetCurrency: self::budgetCurrency(
                $payload['budget_currency'] ?? null,
            ),
            automaticRetryLimit: self::automaticRetryLimit(
                $payload['automatic_retry_limit'] ?? null,
            ),
            autonomyLevel: self::autonomyLevel(
                $payload['autonomy_level'] ?? null,
            ),
            approvalPolicy: self::approvalPolicy(
                $payload['approval_policy'] ?? null,
            ),
            notificationPolicy: self::notificationPolicy(
                $payload['notification_policy'] ?? null,
            ),
        );
    }

    /**
     * Return canonical attributes for ProjectConfiguration persistence.
     *
     * @return array{
     *     default_reasoning: ReasoningLevel,
     *     provider_policy: array{
     *         allowed_provider_ids: list<string>,
     *         fallback_order: list<string>
     *     },
     *     budget_limit_minor: int|null,
     *     budget_currency: string,
     *     automatic_retry_limit: int,
     *     autonomy_level: AutonomyLevel,
     *     approval_policy: array{
     *         roadmap_required: bool,
     *         ticket_execution_required: bool,
     *         merge_required: bool
     *     },
     *     notification_policy: array{
     *         channels: list<string>,
     *         events: list<string>
     *     }
     * }
     */
    public function toPersistenceAttributes(): array
    {
        return [
            'default_reasoning' => $this->defaultReasoning,
            'provider_policy' => $this->providerPolicy->toArray(),
            'budget_limit_minor' => $this->budgetLimitMinor,
            'budget_currency' => $this->budgetCurrency,
            'automatic_retry_limit' => $this->automaticRetryLimit,
            'autonomy_level' => $this->autonomyLevel,
            'approval_policy' => $this->approvalPolicy,
            'notification_policy' => $this->notificationPolicy,
        ];
    }

    /**
     * Resolve the configured default reasoning enum.
     */
    private static function reasoningLevel(mixed $value): ReasoningLevel
    {
        if ($value instanceof ReasoningLevel) {
            return $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                'Default reasoning must be a valid reasoning level.',
            );
        }

        $reasoningLevel = ReasoningLevel::tryFrom(
            strtolower(trim($value)),
        );

        if ($reasoningLevel === null) {
            throw new InvalidArgumentException(
                'Default reasoning must be low, medium, or high.',
            );
        }

        return $reasoningLevel;
    }

    /**
     * Resolve and validate the provider policy.
     */
    private static function providerPolicy(mixed $value): ProviderPolicy
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException(
                'Provider policy must be an array.',
            );
        }

        /** @var array<string, mixed> $value */
        return ProviderPolicy::fromArray($value);
    }

    /**
     * Normalize the optional project budget in minor currency units.
     */
    private static function budgetLimitMinor(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::integerWithinRange(
            value: $value,
            label: 'Budget limit',
            minimum: 0,
            maximum: self::MAX_BUDGET_LIMIT_MINOR,
        );
    }

    /**
     * Normalize a three-letter uppercase currency code.
     */
    private static function budgetCurrency(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException(
                'Budget currency must be a three-letter currency code.',
            );
        }

        $currency = strtoupper(trim($value));

        if (preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw new InvalidArgumentException(
                'Budget currency must contain exactly three ASCII letters.',
            );
        }

        return $currency;
    }

    /**
     * Normalize the maximum automatic retry count.
     */
    private static function automaticRetryLimit(mixed $value): int
    {
        return self::integerWithinRange(
            value: $value,
            label: 'Automatic retry limit',
            minimum: 0,
            maximum: self::MAX_AUTOMATIC_RETRY_LIMIT,
        );
    }

    /**
     * Resolve the configured autonomy enum.
     */
    private static function autonomyLevel(mixed $value): AutonomyLevel
    {
        if ($value instanceof AutonomyLevel) {
            return $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                'Autonomy level must be valid.',
            );
        }

        $autonomyLevel = AutonomyLevel::tryFrom(
            strtolower(trim($value)),
        );

        if ($autonomyLevel === null) {
            throw new InvalidArgumentException(
                'Autonomy level must be advisory, approval_required, or policy_controlled.',
            );
        }

        return $autonomyLevel;
    }

    /**
     * Validate the three explicit human-approval switches.
     *
     * @return array{
     *     roadmap_required: bool,
     *     ticket_execution_required: bool,
     *     merge_required: bool
     * }
     */
    private static function approvalPolicy(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException(
                'Approval policy must be an array.',
            );
        }

        /** @var array<string, mixed> $value */
        self::assertExactKeys(
            value: $value,
            expectedKeys: [
                'roadmap_required',
                'ticket_execution_required',
                'merge_required',
            ],
            label: 'Approval policy',
        );

        return [
            'roadmap_required' => self::requiredBoolean(
                policy: $value,
                key: 'roadmap_required',
            ),
            'ticket_execution_required' => self::requiredBoolean(
                policy: $value,
                key: 'ticket_execution_required',
            ),
            'merge_required' => self::requiredBoolean(
                policy: $value,
                key: 'merge_required',
            ),
        ];
    }

    /**
     * Validate the current in-app notification policy.
     *
     * @return array{
     *     channels: list<string>,
     *     events: list<string>
     * }
     */
    private static function notificationPolicy(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException(
                'Notification policy must be an array.',
            );
        }

        /** @var array<string, mixed> $value */
        self::assertExactKeys(
            value: $value,
            expectedKeys: ['channels', 'events'],
            label: 'Notification policy',
        );

        $channels = $value['channels'] ?? null;

        if ($channels !== ['in_app']) {
            throw new InvalidArgumentException(
                'The MVP notification channel must be in_app.',
            );
        }

        return [
            'channels' => ['in_app'],
            'events' => self::notificationEvents(
                $value['events'] ?? null,
            ),
        ];
    }

    /**
     * Normalize notification event identifiers as a canonical set.
     *
     * @return list<string>
     */
    private static function notificationEvents(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException(
                'Notification events must be a list.',
            );
        }

        if (count($value) > self::MAX_NOTIFICATION_EVENTS) {
            throw new InvalidArgumentException(sprintf(
                'Notification policy may contain at most %d events.',
                self::MAX_NOTIFICATION_EVENTS,
            ));
        }

        $events = [];
        $seenEvents = [];

        foreach ($value as $event) {
            if (! is_string($event)) {
                throw new InvalidArgumentException(
                    'Every notification event must be a string.',
                );
            }

            $normalizedEvent = trim($event);

            if (
                $normalizedEvent === ''
                || mb_strlen($normalizedEvent)
                    > self::MAX_NOTIFICATION_EVENT_LENGTH
            ) {
                throw new InvalidArgumentException(
                    'A notification event identifier is invalid.',
                );
            }

            if (isset($seenEvents[$normalizedEvent])) {
                throw new InvalidArgumentException(sprintf(
                    'Notification event [%s] is duplicated.',
                    $normalizedEvent,
                ));
            }

            $seenEvents[$normalizedEvent] = true;
            $events[] = $normalizedEvent;
        }

        /*
         * Event selection is set-like. Sorting avoids revisions caused only by
         * a different presentation order.
         */
        sort($events, SORT_STRING);

        return $events;
    }

    /**
     * Read one required boolean from a policy array.
     *
     * @param  array<string, mixed>  $policy
     */
    private static function requiredBoolean(
        array $policy,
        string $key,
    ): bool {
        $value = $policy[$key] ?? null;

        if (! is_bool($value)) {
            throw new InvalidArgumentException(sprintf(
                'Approval policy field [%s] must be boolean.',
                $key,
            ));
        }

        return $value;
    }

    /**
     * Validate an exact object-like array schema.
     *
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expectedKeys
     */
    private static function assertExactKeys(
        array $value,
        array $expectedKeys,
        string $label,
    ): void {
        $actualKeys = array_keys($value);

        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);

        if ($actualKeys !== $expectedKeys) {
            throw new InvalidArgumentException(sprintf(
                '%s contains missing or unsupported fields.',
                $label,
            ));
        }
    }

    /**
     * Convert an integer-like value and enforce an inclusive range.
     */
    private static function integerWithinRange(
        mixed $value,
        string $label,
        int $minimum,
        int $maximum,
    ): int {
        $candidate = is_string($value)
            ? trim($value)
            : $value;

        $validated = filter_var(
            $candidate,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => $minimum,
                    'max_range' => $maximum,
                ],
            ],
        );

        if ($validated === false) {
            throw new InvalidArgumentException(sprintf(
                '%s must be an integer between %d and %d.',
                $label,
                $minimum,
                $maximum,
            ));
        }

        return $validated;
    }
}
