<?php

declare(strict_types=1);

namespace App\Domain\Documents;

enum DocumentStatus: string
{
    case Uploaded = 'uploaded';
    case Quarantined = 'quarantined';
    case ScanPending = 'scan_pending';
    case ScanFailed = 'scan_failed';
    case ScanApproved = 'scan_approved';
    case Parsing = 'parsing';
    case ParseFailed = 'parse_failed';
    case Parsed = 'parsed';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}
