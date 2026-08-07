<?php

declare(strict_types=1);

namespace App\Domain\Projects\Configuration;

use App\Domain\Executions\ExecutionCapability;
use InvalidArgumentException;

/**
 * Represents the security-sensitive project policy applied to Codex execution.
 *
 * This configuration remains inert until a later ticket registers a real Codex
 * execution provider. The policy exists now so enablement cannot happen without
 * explicit capability, reasoning, sandbox, network, budget, timeout, and retry
 * constraints already being persisted and snapshotted.
 */
final readonly class CodexProviderPolicy
{
    public const string DEFAULT_MODEL_IDENTIFIER = 'gpt-5.3-codex';

    public const int MIN_TIMEOUT_SECONDS = 30;

    public const int MAX_TIMEOUT_SECONDS = 3600;

    public const int MAX_RETRY_LIMIT = 3;

    private const string MODEL_IDENTIFIER_PATTERN =
        '/\A[a-z0-9][a-z0-9._:-]{0,99}\z/D';

    /**
     * @param  list<string>  $allowedCapabilities
     * @param  array{minimum: string, maximum: string}  $reasoning
     * @param  array{planning: string, development: string, quality_assurance: string}  $sandbox
     * @param  array{default: string, allow_escalation_with_approval: bool}  $network
     */
    private function __construct(
        public bool $enabled,
        public string $modelIdentifier,
        public array $allowedCapabilities,
        public array $reasoning,
        public array $sandbox,
        public array $network,
        public ?int $budgetLimitMinor,
        public int $timeoutSeconds,
        public int $retryLimit,
    ) {}

    /**
     * Return conservative defaults aligned with the approved Codex ADR.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'model_identifier' => self::DEFAULT_MODEL_IDENTIFIER,
            'allowed_capabilities' => [
                ExecutionCapability::PlanningGenerate->value,
                ExecutionCapability::DevelopmentExecute->value,
                ExecutionCapability::QualityAssuranceReview->value,
            ],
            'reasoning' => [
                'minimum' => ReasoningLevel::Medium->value,
                'maximum' => ReasoningLevel::High->value,
            ],
            'sandbox' => [
                'planning' => 'read-only',
                'development' => 'workspace-write',
                'quality_assurance' => 'read-only',
            ],
            'network' => [
                'default' => 'deny',
                'allow_escalation_with_approval' => true,
            ],
            'budget_limit_minor' => null,
            'timeout_seconds' => 900,
            'retry_limit' => 2,
        ];
    }

    /**
     * Build and validate the canonical Codex policy.
     *
     * @param  array<string, mixed>  $policy
     */
    public static function fromArray(array $policy): self
    {
        self::assertExactKeys(
            $policy,
            array_keys(self::defaults()),
            'Codex provider policy',
        );

        $enabled = $policy['enabled'] ?? null;

        if (! is_bool($enabled)) {
            throw new InvalidArgumentException(
                'Codex provider policy enabled must be boolean.',
            );
        }

        $modelIdentifier = self::modelIdentifier(
            $policy['model_identifier'] ?? null,
        );

        $allowedCapabilities = self::allowedCapabilities(
            $policy['allowed_capabilities'] ?? null,
        );

        if ($enabled && $allowedCapabilities === []) {
            throw new InvalidArgumentException(
                'Enabled Codex provider policy requires at least one allowed capability.',
            );
        }

        $reasoning = self::reasoningPolicy(
            $policy['reasoning'] ?? null,
        );

        $sandbox = self::sandboxPolicy(
            $policy['sandbox'] ?? null,
        );

        $network = self::networkPolicy(
            $policy['network'] ?? null,
        );

        $budgetLimitMinor = self::nullableIntegerWithinRange(
            value: $policy['budget_limit_minor'] ?? null,
            label: 'Codex budget limit',
            minimum: 0,
            maximum: ProjectPolicyConfiguration::MAX_BUDGET_LIMIT_MINOR,
        );

        $timeoutSeconds = self::integerWithinRange(
            value: $policy['timeout_seconds'] ?? null,
            label: 'Codex timeout',
            minimum: self::MIN_TIMEOUT_SECONDS,
            maximum: self::MAX_TIMEOUT_SECONDS,
        );

        $retryLimit = self::integerWithinRange(
            value: $policy['retry_limit'] ?? null,
            label: 'Codex retry limit',
            minimum: 0,
            maximum: self::MAX_RETRY_LIMIT,
        );

        return new self(
            enabled: $enabled,
            modelIdentifier: $modelIdentifier,
            allowedCapabilities: $allowedCapabilities,
            reasoning: $reasoning,
            sandbox: $sandbox,
            network: $network,
            budgetLimitMinor: $budgetLimitMinor,
            timeoutSeconds: $timeoutSeconds,
            retryLimit: $retryLimit,
        );
    }

    /**
     * Assert Codex-specific limits remain inside the enclosing project policy.
     */
    public function assertWithinProjectPolicy(
        ReasoningLevel $projectDefaultReasoning,
        ?int $projectBudgetLimitMinor,
        int $projectAutomaticRetryLimit,
    ): void {
        if (! $this->enabled) {
            return;
        }

        $minimum = ReasoningLevel::from($this->reasoning['minimum']);
        $maximum = ReasoningLevel::from($this->reasoning['maximum']);

        if (
            self::reasoningRank($projectDefaultReasoning)
                < self::reasoningRank($minimum)
            || self::reasoningRank($projectDefaultReasoning)
                > self::reasoningRank($maximum)
        ) {
            throw new InvalidArgumentException(
                'Project default reasoning must fall within the configured Codex reasoning limits.',
            );
        }

        if (
            $projectBudgetLimitMinor !== null
            && $this->budgetLimitMinor !== null
            && $this->budgetLimitMinor > $projectBudgetLimitMinor
        ) {
            throw new InvalidArgumentException(
                'Codex budget limit cannot exceed the project budget limit.',
            );
        }

        if ($this->retryLimit > $projectAutomaticRetryLimit) {
            throw new InvalidArgumentException(
                'Codex retry limit cannot exceed the project automatic retry limit.',
            );
        }
    }

    /**
     * Return the JSON-compatible persistence representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'model_identifier' => $this->modelIdentifier,
            'allowed_capabilities' => $this->allowedCapabilities,
            'reasoning' => $this->reasoning,
            'sandbox' => $this->sandbox,
            'network' => $this->network,
            'budget_limit_minor' => $this->budgetLimitMinor,
            'timeout_seconds' => $this->timeoutSeconds,
            'retry_limit' => $this->retryLimit,
        ];
    }

    /**
     * Normalize the configured model identifier without hard-coding token shape.
     */
    private static function modelIdentifier(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException(
                'Codex model identifier must be a string.',
            );
        }

        $normalized = trim($value);

        if (preg_match(self::MODEL_IDENTIFIER_PATTERN, $normalized) !== 1) {
            throw new InvalidArgumentException(
                'Codex model identifier has an invalid format.',
            );
        }

        return $normalized;
    }

    /**
     * Validate and canonicalize supported execution capabilities.
     *
     * @return list<string>
     */
    private static function allowedCapabilities(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException(
                'Codex allowed capabilities must be a list.',
            );
        }

        if (count($value) > count(ExecutionCapability::cases())) {
            throw new InvalidArgumentException(
                'Codex allowed capabilities contain unsupported entries.',
            );
        }

        $capabilities = [];

        foreach ($value as $capability) {
            if (! is_string($capability)) {
                throw new InvalidArgumentException(
                    'Every Codex capability must be a string.',
                );
            }

            $normalized = trim($capability);

            if (ExecutionCapability::tryFrom($normalized) === null) {
                throw new InvalidArgumentException(sprintf(
                    'Codex capability [%s] is not canonical.',
                    $normalized,
                ));
            }

            $capabilities[] = $normalized;
        }

        if (count(array_unique($capabilities)) !== count($capabilities)) {
            throw new InvalidArgumentException(
                'Codex allowed capabilities must not contain duplicates.',
            );
        }

        sort($capabilities, SORT_STRING);

        return array_values($capabilities);
    }

    /**
     * Validate the configured minimum and maximum AIOS reasoning levels.
     *
     * @return array{minimum: string, maximum: string}
     */
    private static function reasoningPolicy(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException(
                'Codex reasoning policy must be an object.',
            );
        }

        self::assertExactKeys(
            $value,
            ['minimum', 'maximum'],
            'Codex reasoning policy',
        );

        $minimum = self::reasoningLevel(
            $value['minimum'] ?? null,
            'minimum',
        );
        $maximum = self::reasoningLevel(
            $value['maximum'] ?? null,
            'maximum',
        );

        if (self::reasoningRank($minimum) > self::reasoningRank($maximum)) {
            throw new InvalidArgumentException(
                'Codex minimum reasoning cannot exceed maximum reasoning.',
            );
        }

        return [
            'minimum' => $minimum->value,
            'maximum' => $maximum->value,
        ];
    }

    /**
     * Validate least-privilege sandbox defaults from the approved Codex ADR.
     *
     * @return array{planning: string, development: string, quality_assurance: string}
     */
    private static function sandboxPolicy(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException(
                'Codex sandbox policy must be an object.',
            );
        }

        self::assertExactKeys(
            $value,
            ['planning', 'development', 'quality_assurance'],
            'Codex sandbox policy',
        );

        $planning = $value['planning'] ?? null;
        $development = $value['development'] ?? null;
        $qualityAssurance = $value['quality_assurance'] ?? null;

        if ($planning !== 'read-only') {
            throw new InvalidArgumentException(
                'Codex planning sandbox must remain read-only.',
            );
        }

        if (! in_array($development, ['read-only', 'workspace-write'], true)) {
            throw new InvalidArgumentException(
                'Codex development sandbox must be read-only or workspace-write.',
            );
        }

        if ($qualityAssurance !== 'read-only') {
            throw new InvalidArgumentException(
                'Codex QA sandbox must remain read-only.',
            );
        }

        return [
            'planning' => 'read-only',
            'development' => $development,
            'quality_assurance' => 'read-only',
        ];
    }

    /**
     * Enforce deny-by-default networking and explicit approval for escalation.
     *
     * @return array{default: string, allow_escalation_with_approval: bool}
     */
    private static function networkPolicy(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException(
                'Codex network policy must be an object.',
            );
        }

        self::assertExactKeys(
            $value,
            ['default', 'allow_escalation_with_approval'],
            'Codex network policy',
        );

        if (($value['default'] ?? null) !== 'deny') {
            throw new InvalidArgumentException(
                'Codex network policy must remain deny by default.',
            );
        }

        $allowEscalation = $value['allow_escalation_with_approval'] ?? null;

        if (! is_bool($allowEscalation)) {
            throw new InvalidArgumentException(
                'Codex network escalation flag must be boolean.',
            );
        }

        return [
            'default' => 'deny',
            'allow_escalation_with_approval' => $allowEscalation,
        ];
    }

    /**
     * Resolve one validated reasoning enum.
     */
    private static function reasoningLevel(
        mixed $value,
        string $label,
    ): ReasoningLevel {
        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Codex %s reasoning must be low, medium, or high.',
                $label,
            ));
        }

        $reasoning = ReasoningLevel::tryFrom(strtolower(trim($value)));

        if ($reasoning === null) {
            throw new InvalidArgumentException(sprintf(
                'Codex %s reasoning must be low, medium, or high.',
                $label,
            ));
        }

        return $reasoning;
    }

    /**
     * Convert an AIOS reasoning level to a comparable rank.
     */
    private static function reasoningRank(ReasoningLevel $level): int
    {
        return match ($level) {
            ReasoningLevel::Low => 1,
            ReasoningLevel::Medium => 2,
            ReasoningLevel::High => 3,
        };
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
        $candidate = is_string($value) ? trim($value) : $value;
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

    /**
     * Normalize an optional integer policy value.
     */
    private static function nullableIntegerWithinRange(
        mixed $value,
        string $label,
        int $minimum,
        int $maximum,
    ): ?int {
        if ($value === null || $value === '') {
            return null;
        }

        return self::integerWithinRange(
            value: $value,
            label: $label,
            minimum: $minimum,
            maximum: $maximum,
        );
    }
}
