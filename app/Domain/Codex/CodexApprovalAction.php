<?php

declare(strict_types=1);

namespace App\Domain\Codex;

/**
 * Defines the application-owned outcomes available for a Codex approval.
 *
 * Defer intentionally does not resolve the underlying generic approval.
 * It leaves the approval pending until a later decision or expiry.
 */
enum CodexApprovalAction: string
{
    case Approve = 'approve';
    case Deny = 'deny';
    case Defer = 'defer';
    case Cancel = 'cancel';
}
