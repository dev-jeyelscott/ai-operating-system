<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Redaction Corpus Version
    |--------------------------------------------------------------------------
    |
    | Increment this value whenever the approved built-in corpus materially
    | changes. Version 2 adds log, error, notification, and artifact coverage.
    |
    */

    'corpus_version' => 2,

    /*
    |--------------------------------------------------------------------------
    | Approved Built-in Patterns
    |--------------------------------------------------------------------------
    |
    | Pattern matching is defense in depth. Sensitive-key redaction and
    | explicit credential boundaries remain the primary controls.
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
            'id' => 'notion_current_token',
            'expression' => '/\bntn_[A-Za-z0-9_-]{20,}\b/',
        ],
        [
            'id' => 'notion_legacy_token',
            'expression' => '/\bsecret_[A-Za-z0-9_-]{20,}\b/',
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
            'id' => 'basic_authorization',
            'expression' => '/(?i)\bBasic\s+[A-Za-z0-9+\/=]{8,}/',
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
            'id' => 'credentialed_http_url',
            'expression' => '~(?i)\bhttps?://[^\s:@/]+:[^@\s/]+@[^\s]+~',
        ],
        [
            'id' => 'sensitive_query_parameter',
            'expression' => '~(?i)[?&](?:api[_-]?key|access[_-]?token|refresh[_-]?token|token|secret|password|x-amz-signature|x-amz-credential|x-amz-security-token)=[^&#\s]+~',
        ],
        [
            'id' => 'slack_webhook_url',
            'expression' => '~https://hooks\.slack\.com/services/[A-Za-z0-9/_-]+~',
        ],
        [
            'id' => 'private_key_block',
            'expression' => '/-----BEGIN(?: [A-Z0-9]+)? PRIVATE KEY-----[\s\S]*?-----END(?: [A-Z0-9]+)? PRIVATE KEY-----/',
        ],
        [
            'id' => 'named_secret_assignment',
            'expression' => '/(?i)\b(?:aws_secret_access_key|client_secret|private_key|access[_-]?token|refresh[_-]?token|api[_-]?key|password|secret|token)\s*[:=]\s*[^\s,;]+/',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Approved Custom Patterns
    |--------------------------------------------------------------------------
    |
    | Deployment-specific patterns must be reviewed before being added.
    |
    */

    'custom_patterns' => [],
];
