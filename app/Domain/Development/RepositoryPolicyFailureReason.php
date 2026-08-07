<?php

declare(strict_types=1);

namespace App\Domain\Development;

enum RepositoryPolicyFailureReason: string
{
    case InvalidTicketType = 'invalid_ticket_type';
    case InvalidSourceBranch = 'invalid_source_branch';
    case ProtectedSource = 'protected_source';
    case BlankTarget = 'blank_target';
    case MainTarget = 'main_target';
    case ProtectedTarget = 'protected_target';
    case UnapprovedTarget = 'unapproved_target';
    case SourceEqualsTarget = 'source_equals_target';
    case DirectPushProtected = 'direct_push_protected';
}
