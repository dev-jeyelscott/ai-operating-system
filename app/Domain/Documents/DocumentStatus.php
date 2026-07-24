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

    /**
     * Legacy state retained for safe migration and backward compatibility.
     *
     * Normal production processing must transition directly from Parsing to
     * AnalysisPending instead of stopping here.
     */
    case Parsed = 'parsed';

    case AnalysisPending = 'analysis_pending';
    case Analyzing = 'analyzing';
    case AnalysisFailed = 'analysis_failed';
    case NeedsReview = 'needs_review';

    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}
