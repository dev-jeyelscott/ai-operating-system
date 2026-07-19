<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'notion' => [
        /*
        * Per-project credentials are stored in provider_credentials.
        * Do not add a global Notion token here.
        */
        'base_url' => env(
            'NOTION_API_BASE_URL',
            'https://api.notion.com/v1',
        ),

        'version' => env(
            'NOTION_API_VERSION',
            '2026-03-11',
        ),

        'connect_timeout_seconds' => (int) env(
            'NOTION_CONNECT_TIMEOUT_SECONDS',
            3,
        ),

        'timeout_seconds' => (int) env(
            'NOTION_TIMEOUT_SECONDS',
            8,
        ),

        'retry_attempts' => (int) env(
            'NOTION_RETRY_ATTEMPTS',
            3,
        ),
    ],
];
