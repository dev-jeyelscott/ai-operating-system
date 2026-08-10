<?php

declare(strict_types=1);

namespace App\Application\Operations;

/**
 * Resolves the canonical office room for one authoritative agent state.
 *
 * The workflow engine and office projection remain authoritative. This class
 * only translates projected state into the stable room vocabulary shared by
 * the 3D office and its accessible equivalent.
 */
final readonly class ResolveOfficeAgentRoom
{
    /**
     * Return the state-aware room for one projected logical agent.
     */
    public function handle(
        string $layer,
        string $officeState,
    ): string {
        return match ($officeState) {
            'waiting_for_approval',
            'waiting_for_human' => 'approval_room',

            'blocked',
            'retrying',
            'failed',
            'cancelled' => 'operations_area',

            'completed' => 'archive',

            default => $this->roomForLayer($layer),
        };
    }

    /**
     * Return the normal working room for one workflow layer.
     */
    private function roomForLayer(string $layer): string
    {
        return match ($layer) {
            'planning' => 'planning_room',
            'development' => 'development_floor',
            'quality_assurance' => 'qa_laboratory',
            default => 'operations_area',
        };
    }
}
