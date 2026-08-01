<?php

use App\Logging\ConfigureStructuredLogging;
use App\Logging\RedactSensitiveLogRecords;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [
    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => explode(
                ',',
                (string) env('LOG_STACK', 'single'),
            ),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'tap' => [
                RedactSensitiveLogRecords::class,
            ],
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'tap' => [
                RedactSensitiveLogRecords::class,
            ],
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env(
                'LOG_SLACK_USERNAME',
                env('APP_NAME', 'Laravel'),
            ),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'tap' => [
                RedactSensitiveLogRecords::class,
            ],
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env(
                'LOG_PAPERTRAIL_HANDLER',
                SyslogUdpHandler::class,
            ),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'
                    .env('PAPERTRAIL_URL')
                    .':'
                    .env('PAPERTRAIL_PORT'),
            ],
            'processors' => [
                PsrLogMessageProcessor::class,
            ],
            'tap' => [
                RedactSensitiveLogRecords::class,
            ],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [
                PsrLogMessageProcessor::class,
            ],
            'tap' => [
                RedactSensitiveLogRecords::class,
            ],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env(
                'LOG_SYSLOG_FACILITY',
                LOG_USER,
            ),
            'tap' => [
                RedactSensitiveLogRecords::class,
            ],
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'tap' => [
                RedactSensitiveLogRecords::class,
            ],
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        /*
         * Laravel creates the emergency logger independently if configured
         * logging cannot boot. Application code must therefore continue using
         * generic exception messages and must never attach raw request data.
         */
        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        'json' => [
            'driver' => 'daily',
            'path' => storage_path(
                'logs/laravel.json.log',
            ),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'tap' => [
                RedactSensitiveLogRecords::class,
                ConfigureStructuredLogging::class,
            ],
            'replace_placeholders' => true,
        ],
    ],
];
