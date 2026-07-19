<?php

declare(strict_types=1);

namespace App\Domain\Projects;

/**
 * Defines the ordered steps in the project configuration wizard.
 *
 * The enum is the canonical source for persisted step names, route
 * constraints, navigation order, labels, and completion sequencing.
 */
enum ProjectSetupStep: string
{
    case Details = 'details';
    case Repository = 'repository';
    case Commands = 'commands';
    case Policies = 'policies';
    case Review = 'review';

    /**
     * Return persisted step values for route constraints and validation.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $step): string => $step->value,
            self::cases(),
        );
    }

    /**
     * Return the human-readable wizard-step label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Details => 'Technology stack',
            self::Repository => 'Repository',
            self::Commands => 'Validation commands',
            self::Policies => 'Policies',
            self::Review => 'Review',
        };
    }

    /**
     * Return a short explanation displayed by the wizard.
     */
    public function description(): string
    {
        return match ($this) {
            self::Details => 'Describe the primary languages, frameworks, databases, runtimes, and infrastructure.',
            self::Repository => 'Store repository metadata without performing repository writes.',
            self::Commands => 'Record the commands later execution providers must validate.',
            self::Policies => 'Configure reasoning, providers, budget, retries, autonomy, approvals, and notifications.',
            self::Review => 'Review the configuration and confirm the persisted setup.',
        };
    }

    /**
     * Return the stable zero-based position of the step.
     */
    public function position(): int
    {
        return match ($this) {
            self::Details => 0,
            self::Repository => 1,
            self::Commands => 2,
            self::Policies => 3,
            self::Review => 4,
        };
    }

    /**
     * Return the immediately preceding step.
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::Details => null,
            self::Repository => self::Details,
            self::Commands => self::Repository,
            self::Policies => self::Commands,
            self::Review => self::Policies,
        };
    }

    /**
     * Return the immediately following step.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::Details => self::Repository,
            self::Repository => self::Commands,
            self::Commands => self::Policies,
            self::Policies => self::Review,
            self::Review => null,
        };
    }
}
