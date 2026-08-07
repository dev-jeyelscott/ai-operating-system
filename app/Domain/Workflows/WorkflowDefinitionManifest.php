<?php

declare(strict_types=1);

namespace App\Domain\Workflows;

use InvalidArgumentException;

/**
 * Defines one immutable, canonical workflow-definition version.
 */
final readonly class WorkflowDefinitionManifest
{
    private const KEY_PATTERN = '/\A[a-z][a-z0-9_.-]{2,99}\z/';

    private const STATE_PATTERN = '/\A[a-z][a-z0-9_]{1,99}\z/';

    private const TRANSITION_PATTERN = '/\A[a-z][a-z0-9_.-]{1,119}\z/';

    private const MAX_NAME_LENGTH = 191;

    private const MAX_DESCRIPTION_LENGTH = 2000;

    public string $definitionKey;

    public int $version;

    public int $schemaVersion;

    public string $name;

    public ?string $description;

    public string $initialState;

    /** @var list<string> */
    public array $states;

    /** @var list<string> */
    public array $terminalStates;

    /**
     * @var list<array{
     *     name: string,
     *     from: string,
     *     to: string,
     *     guard: string|null
     * }>
     */
    public array $transitions;

    /**
     * Normalize and validate one complete workflow-definition manifest.
     *
     * @param  list<string>  $states
     * @param  list<string>  $terminalStates
     * @param  list<array{
     *     name: string,
     *     from: string,
     *     to: string,
     *     guard?: string|null
     * }>  $transitions
     */
    public function __construct(
        string $definitionKey,
        int $version,
        int $schemaVersion,
        string $name,
        ?string $description,
        string $initialState,
        array $states,
        array $terminalStates,
        array $transitions,
    ) {
        if ($version < 1) {
            throw new InvalidArgumentException(
                'The workflow-definition version must be at least one.',
            );
        }

        if ($schemaVersion < 1) {
            throw new InvalidArgumentException(
                'The workflow-definition schema version must be at least one.',
            );
        }

        $normalizedStates = self::normalizeStates($states, 'states');

        if ($normalizedStates === []) {
            throw new InvalidArgumentException(
                'A workflow definition must contain at least one state.',
            );
        }

        $normalizedTerminalStates = self::normalizeStates(
            $terminalStates,
            'terminal states',
        );

        if ($normalizedTerminalStates === []) {
            throw new InvalidArgumentException(
                'A workflow definition must contain at least one terminal state.',
            );
        }

        $normalizedInitialState = self::normalizeState($initialState);

        if (! in_array($normalizedInitialState, $normalizedStates, true)) {
            throw new InvalidArgumentException(
                'The workflow initial state must exist in the state list.',
            );
        }

        foreach ($normalizedTerminalStates as $terminalState) {
            if (! in_array($terminalState, $normalizedStates, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Terminal state "%s" must exist in the state list.',
                    $terminalState,
                ));
            }
        }

        $normalizedTransitions = self::normalizeTransitions(
            transitions: $transitions,
            states: $normalizedStates,
            terminalStates: $normalizedTerminalStates,
        );

        if ($normalizedTransitions === []) {
            throw new InvalidArgumentException(
                'A workflow definition must contain at least one transition.',
            );
        }

        $this->definitionKey = self::normalizeDefinitionKey($definitionKey);
        $this->version = $version;
        $this->schemaVersion = $schemaVersion;
        $this->name = self::normalizeName($name);
        $this->description = self::normalizeDescription($description);
        $this->initialState = $normalizedInitialState;
        $this->states = $normalizedStates;
        $this->terminalStates = $normalizedTerminalStates;
        $this->transitions = $normalizedTransitions;
    }

    /**
     * Return the canonical JSON document stored with the definition version.
     *
     * @return array{
     *     initial_state: string,
     *     states: list<string>,
     *     terminal_states: list<string>,
     *     transitions: list<array{
     *         name: string,
     *         from: string,
     *         to: string,
     *         guard: string|null
     *     }>
     * }
     */
    public function definition(): array
    {
        return [
            'initial_state' => $this->initialState,
            'states' => $this->states,
            'terminal_states' => $this->terminalStates,
            'transitions' => $this->transitions,
        ];
    }

    /**
     * Return a deterministic checksum for all immutable definition metadata.
     */
    public function checksumSha256(): string
    {
        $encoded = json_encode(
            [
                'definition_key' => $this->definitionKey,
                'version' => $this->version,
                'schema_version' => $this->schemaVersion,
                'name' => $this->name,
                'description' => $this->description,
                'definition' => $this->definition(),
            ],
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        );

        return hash('sha256', $encoded);
    }

    /**
     * Normalize the stable workflow-definition key.
     */
    private static function normalizeDefinitionKey(string $definitionKey): string
    {
        $normalized = trim($definitionKey);

        if (preg_match(self::KEY_PATTERN, $normalized) !== 1) {
            throw new InvalidArgumentException(
                'The workflow-definition key must be a lowercase stable identifier.',
            );
        }

        return $normalized;
    }

    /**
     * Normalize the human-readable definition name.
     */
    private static function normalizeName(string $name): string
    {
        $normalized = trim($name);

        if (
            $normalized === ''
            || mb_strlen($normalized) > self::MAX_NAME_LENGTH
        ) {
            throw new InvalidArgumentException(
                'The workflow-definition name must contain between 1 and 191 characters.',
            );
        }

        return $normalized;
    }

    /**
     * Normalize the optional human-readable description.
     */
    private static function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $normalized = trim($description);

        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > self::MAX_DESCRIPTION_LENGTH) {
            throw new InvalidArgumentException(
                'The workflow-definition description may not exceed 2000 characters.',
            );
        }

        return $normalized;
    }

    /**
     * Normalize and sort a state list for deterministic persistence.
     *
     * @param  list<string>  $states
     * @return list<string>
     */
    private static function normalizeStates(
        array $states,
        string $label,
    ): array {
        $normalized = array_map(
            static fn (string $state): string => self::normalizeState($state),
            $states,
        );

        if (count($normalized) !== count(array_unique($normalized))) {
            throw new InvalidArgumentException(sprintf(
                'Workflow %s must be unique.',
                $label,
            ));
        }

        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * Normalize one state identifier.
     */
    private static function normalizeState(string $state): string
    {
        $normalized = trim($state);

        if (preg_match(self::STATE_PATTERN, $normalized) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid workflow state identifier "%s".',
                $state,
            ));
        }

        return $normalized;
    }

    /**
     * Normalize transitions and validate all state references.
     *
     * @param  list<array{
     *     name: string,
     *     from: string,
     *     to: string,
     *     guard?: string|null
     * }>  $transitions
     * @param  list<string>  $states
     * @param  list<string>  $terminalStates
     * @return list<array{
     *     name: string,
     *     from: string,
     *     to: string,
     *     guard: string|null
     * }>
     */
    private static function normalizeTransitions(
        array $transitions,
        array $states,
        array $terminalStates,
    ): array {
        /** @var list<array{
         *     name: string,
         *     from: string,
         *     to: string,
         *     guard: string|null
         * }> $normalized
         */
        $normalized = [];

        /** @var list<string> $transitionNames */
        $transitionNames = [];

        foreach ($transitions as $transition) {
            $name = self::normalizeTransitionName($transition['name']);
            $from = self::normalizeState($transition['from']);
            $to = self::normalizeState($transition['to']);
            $guard = self::normalizeGuard($transition['guard'] ?? null);

            if (! in_array($from, $states, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Transition "%s" references unknown source state "%s".',
                    $name,
                    $from,
                ));
            }

            if (! in_array($to, $states, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Transition "%s" references unknown target state "%s".',
                    $name,
                    $to,
                ));
            }

            if (in_array($from, $terminalStates, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Terminal state "%s" cannot have outgoing transitions.',
                    $from,
                ));
            }

            $transitionNames[] = $name;

            $normalized[] = [
                'name' => $name,
                'from' => $from,
                'to' => $to,
                'guard' => $guard,
            ];
        }

        if (
            count($transitionNames)
            !== count(array_unique($transitionNames))
        ) {
            throw new InvalidArgumentException(
                'Workflow transition names must be unique.',
            );
        }

        usort(
            $normalized,
            static fn (array $left, array $right): int => [
                $left['name'],
                $left['from'],
                $left['to'],
                $left['guard'] ?? '',
            ] <=> [
                $right['name'],
                $right['from'],
                $right['to'],
                $right['guard'] ?? '',
            ],
        );

        return $normalized;
    }

    /**
     * Normalize one transition identifier.
     */
    private static function normalizeTransitionName(string $name): string
    {
        $normalized = trim($name);

        if (preg_match(self::TRANSITION_PATTERN, $normalized) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid workflow transition identifier "%s".',
                $name,
            ));
        }

        return $normalized;
    }

    /**
     * Normalize an optional guard-policy identifier.
     */
    private static function normalizeGuard(?string $guard): ?string
    {
        if ($guard === null) {
            return null;
        }

        $normalized = trim($guard);

        if ($normalized === '') {
            return null;
        }

        if (preg_match(self::TRANSITION_PATTERN, $normalized) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid workflow guard identifier "%s".',
                $guard,
            ));
        }

        return $normalized;
    }
}
