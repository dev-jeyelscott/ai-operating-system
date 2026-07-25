<?php

declare(strict_types=1);

namespace App\Domain\Workflows;

use App\Domain\Workflows\Exceptions\InvalidWorkflowTransition;

/**
 * Resolves transitions from an immutable workflow-definition document.
 *
 * This class does not write to the database and does not execute guards.
 */
final class WorkflowStateMachine
{
    /**
     * Return the definition's initial state.
     *
     * @param  array{
     *     initial_state: string,
     *     states: list<string>,
     *     terminal_states: list<string>,
     *     transitions: list<array{
     *         name: string,
     *         from: string,
     *         to: string,
     *         guard: string|null
     *     }>
     * }  $definition
     */
    public function initialState(array $definition): string
    {
        return $definition['initial_state'];
    }

    /**
     * Determine whether the supplied state is terminal.
     *
     * @param  array{
     *     initial_state: string,
     *     states: list<string>,
     *     terminal_states: list<string>,
     *     transitions: list<array{
     *         name: string,
     *         from: string,
     *         to: string,
     *         guard: string|null
     *     }>
     * }  $definition
     */
    public function isTerminal(
        array $definition,
        string $state,
    ): bool {
        return in_array(
            $state,
            $definition['terminal_states'],
            true,
        );
    }

    /**
     * Resolve one allowed transition from the supplied current state.
     *
     * @param  array{
     *     initial_state: string,
     *     states: list<string>,
     *     terminal_states: list<string>,
     *     transitions: list<array{
     *         name: string,
     *         from: string,
     *         to: string,
     *         guard: string|null
     *     }>
     * }  $definition
     */
    public function resolve(
        array $definition,
        string $currentState,
        string $transitionName,
    ): WorkflowTransitionDefinition {
        if (! in_array($currentState, $definition['states'], true)) {
            throw InvalidWorkflowTransition::unknownCurrentState(
                $currentState,
            );
        }

        if ($this->isTerminal($definition, $currentState)) {
            throw InvalidWorkflowTransition::fromTerminalState(
                $currentState,
            );
        }

        $normalizedName = trim($transitionName);

        foreach ($definition['transitions'] as $transition) {
            if ($transition['name'] !== $normalizedName) {
                continue;
            }

            if ($transition['from'] !== $currentState) {
                throw InvalidWorkflowTransition::notAllowedFrom(
                    transition: $normalizedName,
                    currentState: $currentState,
                    expectedState: $transition['from'],
                );
            }

            return new WorkflowTransitionDefinition(
                name: $transition['name'],
                from: $transition['from'],
                to: $transition['to'],
                guard: $transition['guard'],
            );
        }

        throw InvalidWorkflowTransition::notDefined(
            $normalizedName,
        );
    }
}
