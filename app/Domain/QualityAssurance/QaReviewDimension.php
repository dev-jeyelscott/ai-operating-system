<?php

declare(strict_types=1);

namespace App\Domain\QualityAssurance;

/**
 * Identifies the specification-defined QA dimension associated with a finding.
 */
enum QaReviewDimension: string
{
    case FunctionalCorrectness = 'functional_correctness';
    case Scope = 'scope';
    case AcceptanceCriteria = 'acceptance_criteria';
    case Architecture = 'architecture';
    case Authorization = 'authorization';
    case Security = 'security';
    case DataIntegrity = 'data_integrity';
    case DatabaseMigration = 'database_migration';
    case Performance = 'performance';
    case Maintainability = 'maintainability';
    case TestCoverage = 'test_coverage';
    case NegativeCases = 'negative_cases';
    case Ci = 'ci';
    case StaticAnalysis = 'static_analysis';
    case DependencySupplyChain = 'dependency_supply_chain';
    case BackwardCompatibility = 'backward_compatibility';
    case Regression = 'regression';
    case Rollback = 'rollback';
    case Observability = 'observability';
    case OperationalImpact = 'operational_impact';
}
