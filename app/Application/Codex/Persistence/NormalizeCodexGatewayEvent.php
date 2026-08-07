<?php

declare(strict_types=1);

namespace App\Application\Codex\Persistence;

use App\Application\Codex\Data\CodexGatewayEvent;
use App\Application\Codex\Exceptions\CodexGatewayException;
use App\Domain\Codex\ProviderEventType;

/**
 * Maps approved App Server methods into stable application event categories.
 */
final readonly class NormalizeCodexGatewayEvent
{
    /**
     * Classify one validated gateway event.
     */
    public function type(
        CodexGatewayEvent $event,
    ): ProviderEventType {
        return match ($event->method) {
            'thread/started' => ProviderEventType::ThreadStarted,

            'thread/status/changed' => ProviderEventType::StatusChanged,

            'thread/tokenUsage/updated' => ProviderEventType::UsageUpdated,

            'turn/started' => ProviderEventType::TurnStarted,

            'turn/completed' => $this->completedTurnType($event),

            'turn/diff/updated' => ProviderEventType::DiffUpdated,

            'item/started' => $this->itemType(
                $event,
                ProviderEventType::ItemStarted,
                ProviderEventType::CommandRequested,
            ),

            'item/completed' => $this->itemType(
                $event,
                ProviderEventType::ItemCompleted,
                ProviderEventType::CommandCompleted,
            ),

            'item/commandExecution/requestApproval',
            'item/fileChange/requestApproval' => ProviderEventType::ApprovalRequested,

            'serverRequest/resolved' => ProviderEventType::ApprovalResolved,

            'item/agentMessage/delta',
            'item/reasoning/textDelta',
            'item/reasoning/summaryTextDelta',
            'item/commandExecution/outputDelta',
            'item/fileChange/outputDelta' => ProviderEventType::OutputDelta,

            'warning',
            'configWarning' => ProviderEventType::Warning,

            default => throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_UNKNOWN_MESSAGE,
                false,
                'Codex event has no approved application normalization.',
            ),
        };
    }

    /**
     * Distinguish successful/interrupted and failed terminal turns.
     */
    private function completedTurnType(
        CodexGatewayEvent $event,
    ): ProviderEventType {
        $turn = $event->payload['turn'] ?? null;

        $status = is_array($turn)
            ? ($turn['status'] ?? null)
            : null;

        return $status === 'failed'
            || $status === 'interrupted'
                ? ProviderEventType::TurnFailed
                : ProviderEventType::TurnCompleted;
    }

    /**
     * Promote command items to explicit command lifecycle categories.
     */
    private function itemType(
        CodexGatewayEvent $event,
        ProviderEventType $normal,
        ProviderEventType $command,
    ): ProviderEventType {
        $item = $event->payload['item'] ?? null;

        if (
            is_array($item)
            && ($item['type'] ?? null)
                === 'commandExecution'
        ) {
            return $command;
        }

        return $normal;
    }
}
