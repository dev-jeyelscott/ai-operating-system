<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Redaction Corpus Version
    |--------------------------------------------------------------------------
    |
    | Increment this value whenever the approved built-in corpus materially
    | changes. Provider-bound artifacts may record this version in later phases.
    |
    */

    'corpus_version' => 1,

    /*
    |--------------------------------------------------------------------------
    | Approved Built-in Patterns
    |--------------------------------------------------------------------------
    |
    | Every entry requires a stable, non-sensitive identifier. Expressions must
    | redact the complete credential-bearing value rather than merely a prefix.
    |
    */

    'patterns' => [
        [
            'id' => 'github_classic_token',
            'expression' => '/\bgh[pousr]_[A-Za-z0-9]{36,255}\b/',
        ],
        [
            'id' => 'github_fine_grained_token',
            'expression' => '/\bgithub_pat_[A-Za-z0-9_]{20,255}\b/',
        ],
        [
            'id' => 'openai_api_key',
            'expression' => '/\bsk-(?:proj-)?[A-Za-z0-9_-]{20,}\b/',
        ],
        [
            'id' => 'aws_access_key',
            'expression' => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        ],
        [
            'id' => 'bearer_credential',
            'expression' => '/(?i)\bBearer\s+[A-Za-z0-9._~+\/=-]{16,}/',
        ],
        [
            'id' => 'json_web_token',
            'expression' => '/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\b/',
        ],
        [
            'id' => 'credentialed_database_url',
            'expression' => '~(?i)\b(?:postgres(?:ql)?|mysql|mariadb|mongodb(?:\+srv)?|redis)://[^\s:@/]+:[^@\s/]+@[^\s]+~',
        ],
        [
            'id' => 'private_key_block',
            'expression' => '/-----BEGIN(?: [A-Z0-9]+)? PRIVATE KEY-----[\s\S]*?-----END(?: [A-Z0-9]+)? PRIVATE KEY-----/',
        ],
        [
            'id' => 'named_secret_assignment',
            'expression' => '/(?i)\b(password|secret|token|api[_-]?key)\s*[:=]\s*[^\s,;]+/',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Approved Custom Patterns
    |--------------------------------------------------------------------------
    |
    | Deployment or organization-approved extensions use the same id and
    | expression contract. Keep this empty until a pattern is formally approved.
    |
    */

    'custom_patterns' => [],
];
