<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Application\Planning\Data\PlanningExecutionResult;

/** Applies one ordered readiness decision to a fully valid roadmap result. */
final class RoadmapReadinessEvaluator
{
    /** @return array{decision:string,reasons:list<string>} */
    public function evaluate(PlanningExecutionResult $result): array
    {
        if ($result->outcome === 'blocked') {
            $reasons = array_map(static fn ($diagnostic): string => $diagnostic->message, $result->diagnostics);
            sort($reasons, SORT_STRING);

            return ['decision' => 'blocked', 'reasons' => $reasons];
        }

        $blocking = [...$result->gaps, ...$result->conflicts];
        if ($blocking !== []) {
            sort($blocking, SORT_STRING);

            return ['decision' => 'blocked', 'reasons' => $blocking];
        }
        if ($result->humanDecisionRequired) {
            return ['decision' => 'human_decision_required', 'reasons' => ['A consequential human decision is required.']];
        }
        if ($result->risks !== []) {
            $reasons = $result->risks;
            sort($reasons, SORT_STRING);

            return ['decision' => 'ready_with_risks', 'reasons' => $reasons];
        }

        return ['decision' => 'ready', 'reasons' => []];
    }
}
