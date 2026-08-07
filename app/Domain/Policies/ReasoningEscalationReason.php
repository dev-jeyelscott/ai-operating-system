<?php

declare(strict_types=1);

namespace App\Domain\Policies;

/**
 * Defines the approved conditions that always require high reasoning.
 */
enum ReasoningEscalationReason: string
{
    case Security = 'security';
    case Authorization = 'authorization';
    case Privacy = 'privacy';
    case Money = 'money';
    case CriticalBusinessData = 'critical_business_data';
    case DestructiveChange = 'destructive_change';
    case ArchitectureConflict = 'architecture_conflict';
    case NonDeterministicFailure = 'non_deterministic_failure';
    case ProductionReliability = 'production_reliability';
    case FinalQa = 'final_qa';
    case MergeDecision = 'merge_decision';

    /**
     * Return a safe human-readable explanation for audit and UI surfaces.
     */
    public function description(): string
    {
        return match ($this) {
            self::Security => 'security-sensitive work',
            self::Authorization => 'authorization changes',
            self::Privacy => 'privacy-sensitive work',
            self::Money => 'money-related behavior',
            self::CriticalBusinessData => 'critical business data',
            self::DestructiveChange => 'destructive changes',
            self::ArchitectureConflict => 'architecture conflicts',
            self::NonDeterministicFailure => 'non-deterministic failures',
            self::ProductionReliability => 'production reliability',
            self::FinalQa => 'final QA',
            self::MergeDecision => 'merge decisions',
        };
    }
}
