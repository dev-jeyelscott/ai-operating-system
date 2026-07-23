<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

/**
 * Represents the most recently persisted Notion connection-test outcome.
 */
enum NotionConnectionStatus: string
{
    case Connected = 'connected';
    case Failed = 'failed';
}
