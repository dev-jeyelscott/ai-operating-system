<?php

declare(strict_types=1);

use App\Http\Responses\ApiErrorResponse;
use App\Logging\RedactSensitiveLogRecords;
use App\Models\Artifact;
use App\Models\Evidence;
use App\Models\ExecutionAttempt;
use App\Models\NotificationEvent;
use App\Support\Security\SensitiveValueRedactor;
use Illuminate\Http\Request;
use LogicException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use RuntimeException;

it(
    'redacts sensitive keys and credentials embedded in strings',
    function (): void {
        $openAiToken = 'sk-proj-'.str_repeat('A', 32);
        $githubToken = 'ghp_'.str_repeat('B', 36);
        $notionToken = 'ntn_'.str_repeat('C', 32);

        $redactor = app(SensitiveValueRedactor::class);

        $safe = $redactor->redact([
            'authorization' => "Bearer {$notionToken}",
            'provider' => [
                'message' => "OpenAI returned {$openAiToken}",
                'callback' => "https://example.test/callback?token={$githubToken}",
            ],
        ]);

        $serialized = json_encode(
            $safe,
            JSON_THROW_ON_ERROR,
        );

        expect($serialized)
            ->not->toContain($openAiToken)
            ->not->toContain($githubToken)
            ->not->toContain($notionToken)
            ->toContain(SensitiveValueRedactor::REDACTED);
    },
);

it(
    'attaches redaction to every writable application log channel',
    function (): void {
        $channels = [
            'single',
            'daily',
            'slack',
            'papertrail',
            'stderr',
            'syslog',
            'errorlog',
            'json',
        ];

        foreach ($channels as $channel) {
            $taps = config(
                "logging.channels.{$channel}.tap",
                [],
            );

            expect($taps)
                ->toBeArray()
                ->toContain(
                    RedactSensitiveLogRecords::class,
                );
        }
    },
);

it(
    'redacts log messages contexts and exception messages',
    function (): void {
        $openAiToken = 'sk-proj-'.str_repeat('A', 32);
        $notionToken = 'ntn_'.str_repeat('C', 32);

        $logger = new Logger('security-audit');
        $handler = new TestHandler;

        $logger->pushHandler($handler);

        app(RedactSensitiveLogRecords::class)(
            $logger,
        );

        $logger->warning(
            "Provider failed using {$openAiToken}",
            [
                'exception' => new RuntimeException(
                    "Notion rejected {$notionToken}",
                ),
                'authorization' => "Bearer {$notionToken}",
            ],
        );

        $records = $handler->getRecords();
        $record = $records[0];

        $serialized = json_encode([
            'message' => $record->message,
            'context' => $record->context,
            'extra' => $record->extra,
        ], JSON_THROW_ON_ERROR);

        expect($serialized)
            ->not->toContain($openAiToken)
            ->not->toContain($notionToken)
            ->toContain(SensitiveValueRedactor::REDACTED);
    },
);

it(
    'redacts public API error messages and details',
    function (): void {
        $token = 'sk-proj-'.str_repeat('A', 32);

        $request = Request::create(
            '/api/security-audit',
            'GET',
        );

        $request->attributes->set(
            'request_id',
            'security-audit-request',
        );

        $response = ApiErrorResponse::make(
            request: $request,
            code: 'provider_failed',
            message: "Provider rejected {$token}",
            status: 500,
            details: [
                'fields' => [
                    'password' => [
                        'The password must contain at least 15 characters.',
                    ],
                ],
                'provider_message' => "Credential {$token}",
            ],
        );

        $content = $response->getContent();

        expect($content)
            ->not->toBeFalse()
            ->not->toContain($token)
            ->toContain(
                'The password must contain at least 15 characters.',
            )
            ->toContain(
                SensitiveValueRedactor::REDACTED,
            );
    },
);

it(
    'sanitizes notification content before persistence',
    function (): void {
        $token = 'ntn_'.str_repeat('C', 32);

        $notification = NotificationEvent::factory()
            ->create([
                'title' => "Token {$token}",
                'message' => "Provider returned {$token}",
                'action_url' => "https://example.test/action?token={$token}",
                'data' => [
                    'provider_token' => $token,
                    'summary' => "Observed {$token}",
                ],
            ])
            ->fresh();

        $serialized = json_encode([
            'title' => $notification->title,
            'message' => $notification->message,
            'action_url' => $notification->action_url,
            'data' => $notification->data,
        ], JSON_THROW_ON_ERROR);

        expect($serialized)
            ->not->toContain($token)
            ->toContain(
                SensitiveValueRedactor::REDACTED,
            );

        expect($notification->action_url)->toBeNull();
    },
);

it(
    'sanitizes persisted execution error messages',
    function (): void {
        $token = 'ghp_'.str_repeat('B', 36);

        $attempt = ExecutionAttempt::factory()->create();

        $attempt->forceFill([
            'error_message' => "GitHub rejected {$token}",
        ])->save();

        expect($attempt->fresh()->error_message)
            ->not->toContain($token)
            ->toContain(
                SensitiveValueRedactor::REDACTED,
            );
    },
);

it(
    'blocks an unsafe immutable artifact',
    function (): void {
        $token = 'sk-proj-'.str_repeat('A', 32);

        expect(
            fn () => Artifact::factory()->create([
                'name' => "Unsafe artifact {$token}",
            ]),
        )->toThrow(
            LogicException::class,
            'Sensitive data was detected in artifact content',
        );
    },
);

it(
    'blocks unsafe immutable evidence',
    function (): void {
        $token = 'ntn_'.str_repeat('C', 32);

        $artifact = Artifact::factory()->create();

        expect(
            fn () => Evidence::factory()
                ->for($artifact)
                ->create([
                    'claims' => [
                        "Unsafe evidence {$token}",
                    ],
                ]),
        )->toThrow(
            LogicException::class,
            'Sensitive data was detected in evidence content',
        );
    },
);
