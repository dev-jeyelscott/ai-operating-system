export type DocumentStatus =
    | 'uploaded'
    | 'quarantined'
    | 'scan_pending'
    | 'scan_failed'
    | 'scan_approved'
    | 'parsing'
    | 'parse_failed'
    | 'parsed'
    | 'analysis_pending'
    | 'analyzing'
    | 'analysis_failed'
    | 'needs_review'
    | 'approved'
    | 'rejected'
    | 'superseded';

export type DocumentActionUrls = {
    approve: string | null;
    reject: string | null;
    replacement: string | null;
    retry: string | null;
};

export type DocumentFailure = {
    code: string | null;
    message: string | null;
};

export type DocumentVersion = {
    id: number;
    version: number;
    originalFilename: string;
    mediaType: string;
    byteSize: number;
    status: DocumentStatus;
    classification: string;
    checksum: string;
    parserName: string | null;
    parserVersion: string | null;
    analyzerName: string | null;
    analyzerVersion: string | null;
    analysisSeed: number | null;
    analysisCompletedAt: string | null;
    summary: string | null;
    conflicts: string[];
    gaps: string[];
    flags: string[];
    failure: DocumentFailure | null;
    actions: DocumentActionUrls;
};

export type ProjectDocument = {
    id: number;
    title: string;
    documentClass: string | null;
    url?: string;
    versions: DocumentVersion[];
};

export type DocumentFlash = {
    status: string | null;
};
