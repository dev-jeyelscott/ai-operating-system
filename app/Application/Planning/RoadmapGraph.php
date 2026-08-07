<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Application\Planning\Data\PlanningExecutionResult;
use InvalidArgumentException;

/** Deterministically validates dependencies and calculates topological and critical-path order. */
final class RoadmapGraph
{
    /**
     * @return array{
     *     order:list<string>,
     *     critical_path:list<string>,
     *     critical_path_rank:array<string,int>,
     *     critical_path_position:array<string,int|null>,
     *     is_critical_path:array<string,bool>,
     *     predecessor:array<string,string|null>
     * }
     */
    public function analyze(PlanningExecutionResult $result): array
    {
        $tasks = [];
        foreach ($result->roadmap->tasks as $task) {
            $tasks[$task->stableId] = $task;
        }
        ksort($tasks, SORT_STRING);
        $incoming = array_fill_keys(array_keys($tasks), 0);
        $outgoing = array_fill_keys(array_keys($tasks), []);
        $seen = [];

        foreach ($result->roadmap->dependencies as $dependency) {
            $task = $dependency->taskId;
            $dependsOn = $dependency->dependsOnTaskId;
            if (! isset($tasks[$task], $tasks[$dependsOn])) {
                throw new InvalidArgumentException('A dependency references a missing task.');
            }
            if ($task === $dependsOn) {
                throw new InvalidArgumentException(sprintf('Task [%s] cannot depend on itself.', $task));
            }
            $key = $task.'|'.$dependsOn;
            if (isset($seen[$key])) {
                throw new InvalidArgumentException(sprintf('Duplicate dependency [%s].', $key));
            }
            $seen[$key] = true;
            $outgoing[$dependsOn][] = $task;
            $incoming[$task]++;
        }

        foreach ($outgoing as &$children) {
            sort($children, SORT_STRING);
        } unset($children);
        $ready = array_keys(array_filter($incoming, static fn (int $count): bool => $count === 0));
        sort($ready, SORT_STRING);
        $order = [];
        $rank = [];
        $predecessor = array_fill_keys(array_keys($tasks), null);
        foreach ($tasks as $taskId => $task) {
            $rank[$taskId] = $task->estimatedComplexity;
        }
        while ($ready !== []) {
            $current = array_shift($ready);
            $order[] = $current;
            foreach ($outgoing[$current] as $child) {
                $childTask = $tasks[$child];
                $candidate = $rank[$current] + $childTask->estimatedComplexity;
                if (
                    $candidate > $rank[$child]
                    || ($candidate === $rank[$child] && ($predecessor[$child] === null || strcmp($current, $predecessor[$child]) < 0))
                ) {
                    $rank[$child] = $candidate;
                    $predecessor[$child] = $current;
                }
                if (--$incoming[$child] === 0) {
                    $ready[] = $child;
                    sort($ready, SORT_STRING);
                }
            }
        }
        if (count($order) !== count($tasks)) {
            $cycle = array_keys(array_filter($incoming, static fn (int $count): bool => $count > 0));
            sort($cycle, SORT_STRING);
            throw new InvalidArgumentException('Dependency cycle detected: '.implode(' -> ', $cycle));
        }

        $endpoint = null;
        foreach (array_keys($tasks) as $taskId) {
            if (
                $endpoint === null
                || $rank[$taskId] > $rank[$endpoint]
                || ($rank[$taskId] === $rank[$endpoint] && strcmp($taskId, $endpoint) < 0)
            ) {
                $endpoint = $taskId;
            }
        }

        $criticalPath = [];
        while ($endpoint !== null) {
            array_unshift($criticalPath, $endpoint);
            $endpoint = $predecessor[$endpoint];
        }

        $criticalPathPosition = array_fill_keys(array_keys($tasks), null);
        $isCriticalPath = array_fill_keys(array_keys($tasks), false);
        foreach ($criticalPath as $position => $taskId) {
            $criticalPathPosition[$taskId] = $position + 1;
            $isCriticalPath[$taskId] = true;
        }

        return [
            'order' => $order,
            'critical_path' => $criticalPath,
            'critical_path_rank' => $rank,
            'critical_path_position' => $criticalPathPosition,
            'is_critical_path' => $isCriticalPath,
            'predecessor' => $predecessor,
        ];
    }
}
