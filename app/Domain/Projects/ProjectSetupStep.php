<?php

declare(strict_types=1);

namespace App\Domain\Projects;

/**
 * Defines the ordered steps in the project configuration wizard.
 *
 * This enum is the canonical source for persisted step names, route
 * constraints, navigation order, labels, and completion sequencing.
 */
enum ProjectSetupStep: string
{
    case Details = 'details';
    case Repository = 'repository';
    case Integrations = 'integrations';
    case Commands = 'commands';
    case Policies = 'policies';
    case Review = 'review';

    /**
     * Return every persisted wizard-step value.
     *
     * All values may be used by the setup display route because every wizard
     * page, including Integrations, must remain directly viewable.
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
     * Return setup steps handled through the generic PUT update route.
     *
     * Integrations is intentionally excluded because its state advances only
     * after the dedicated provider connection-test action succeeds.
     *
     * @return list<string>
     */
    public static function directUpdateValues(): array
    {
        return [
            self::Details->value,
            self::Repository->value,
            self::Commands->value,
            self::Policies->value,
            self::Review->value,
        ];
    }

    /**
     * Return the human-readable wizard-step label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Details => 'Technology stack',
            self::Repository => 'Repository',
            self::Integrations => 'Integrations',
            self::Commands => 'Validation commands',
            self::Policies => 'Policies',
            self::Review => 'Review',
        };
    }

    /**
     * Return the explanation displayed for this wizard step.
     */
    public function description(): string
    {
        return match ($this) {
            self::Details => 'Describe the primary languages, frameworks, databases, runtimes, and infrastructure.',

            self::Repository => 'Store repository metadata without performing repository writes.',

            self::Integrations => 'Validate the external integrations required by this project.',

            self::Commands => 'Record the commands later execution providers must validate.',

            self::Policies => 'Configure reasoning, providers, budget, retries, autonomy, approvals, and notifications.',

            self::Review => 'Review the configuration and confirm the persisted setup.',
        };
    }

    /**
     * Return the stable zero-based position of this step.
     */
    public function position(): int
    {
        return match ($this) {
            self::Details => 0,
            self::Repository => 1,
            self::Integrations => 2,
            self::Commands => 3,
            self::Policies => 4,
            self::Review => 5,
        };
    }

    /**
     * Return the immediately preceding wizard step.
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::Details => null,
            self::Repository => self::Details,
            self::Integrations => self::Repository,
            self::Commands => self::Integrations,
            self::Policies => self::Commands,
            self::Review => self::Policies,
        };
    }

    /**
     * Return project setup steps in their canonical workflow order.
     *
     * Business logic must use this method instead of relying on enum declaration
     * order returned by cases().
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::Details,
            self::Repository,
            self::Integrations,
            self::Commands,
            self::Policies,
            self::Review,
        ];
    }

    /**
     * Return the next step in the canonical project setup workflow.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::Details => self::Repository,
            self::Repository => self::Integrations,
            self::Integrations => self::Commands,
            self::Commands => self::Policies,
            self::Policies => self::Review,
            self::Review => null,
        };
    }
}
