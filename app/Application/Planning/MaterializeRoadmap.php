<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Models\Roadmap;
use InvalidArgumentException;

final class MaterializeRoadmap
{
    /** @return array<string, mixed> */
    public function handle(Roadmap $roadmap): array
    {
        $snapshot = $roadmap->generated_snapshot;

        $roadmap->loadMissing('edits');
        foreach ($roadmap->edits as $edit) {
            $snapshot = $this->apply($snapshot, $edit->patch);
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function apply(array $snapshot, array $patch): array
    {
        $roadmapPatch = $patch['roadmap'] ?? [];
        if (is_array($roadmapPatch)) {
            foreach ($roadmapPatch as $field => $value) {
                $snapshot[$field] = $value;
            }
        }

        $taskPatches = $patch['tasks'] ?? [];
        if (! is_array($taskPatches)) {
            return $snapshot;
        }

        $tasks = $snapshot['roadmap']['tasks'] ?? null;
        if (! is_array($tasks)) {
            throw new InvalidArgumentException('The generated roadmap task collection is invalid.');
        }

        foreach ($tasks as $index => $task) {
            if (! is_array($task) || ! isset($task['stable_id']) || ! is_string($task['stable_id'])) {
                continue;
            }
            $taskPatch = $taskPatches[$task['stable_id']] ?? null;
            if (is_array($taskPatch)) {
                $criteriaPatch = $taskPatch['acceptance_criteria'] ?? null;
                unset($taskPatch['acceptance_criteria']);
                $materializedTask = array_replace($task, $taskPatch);
                if (is_array($criteriaPatch)) {
                    $materializedTask['acceptance_criteria'] = $this->applyCriterionDescriptions(
                        $task['acceptance_criteria'] ?? null,
                        $criteriaPatch,
                    );
                }
                $snapshot['roadmap']['tasks'][$index] = $materializedTask;
            }
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $descriptions
     * @return list<array<string, mixed>>
     */
    private function applyCriterionDescriptions(mixed $criteria, array $descriptions): array
    {
        if (! is_array($criteria)) {
            throw new InvalidArgumentException('The generated task acceptance criteria are invalid.');
        }

        $found = [];
        foreach ($criteria as $index => $criterion) {
            if (! is_array($criterion) || ! isset($criterion['stable_id']) || ! is_string($criterion['stable_id'])) {
                throw new InvalidArgumentException('The generated acceptance criterion is invalid.');
            }
            $description = $descriptions[$criterion['stable_id']] ?? null;
            if ($description !== null) {
                if (! is_string($description) || trim($description) === '') {
                    throw new InvalidArgumentException('The acceptance-criterion description is invalid.');
                }
                $criteria[$index]['description'] = trim($description);
                $found[$criterion['stable_id']] = true;
            }
        }
        if (array_diff_key($descriptions, $found) !== []) {
            throw new InvalidArgumentException('An acceptance-criterion edit references an unknown stable ID.');
        }

        return array_values($criteria);
    }
}
