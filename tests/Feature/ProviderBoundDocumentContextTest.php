<?php

declare(strict_types=1);

use App\Application\Documents\BuildProviderBoundDocumentContext;
use App\Application\Documents\Exceptions\DocumentContextIntegrityException;
use App\Application\Documents\Exceptions\ProviderBoundRedactionException;
use App\Application\Documents\RedactProviderBoundDocumentContext;
use App\Application\Projects\CreateProject;
use App\Application\Projects\CreateProjectContextSnapshot;
use App\Domain\Projects\ProjectType;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\ProjectContextSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config()->set(
        'filesystems.artifact',
        'documents',
    );

    Storage::fake('documents');
});

/**
 * Create one approved document and a version 2 context snapshot.
 *
 * @return array{
 *     organization: Organization,
 *     version: DocumentVersion,
 *     snapshot: ProjectContextSnapshot
 * }
 */
function providerBoundContextFixture(): array
{
    $organization = Organization::factory()->create();

    $project = app(CreateProject::class)->handle(
        actorUserId: User::factory()->create()->id,
        organizationId: $organization->id,
        name: 'Provider context project',
        description: null,
        projectType: ProjectType::WebApplication,
    );

    $document = Document::factory()
        ->for($project)
        ->create();

    $version = DocumentVersion::factory()
        ->for($document)
        ->approved()
        ->create([
            'parsed_content' => implode(' ', [
                'token=super-secret',
                'ghp_abcdefghijklmnopqrstuvwxyz1234567890',
            ]),
            'analysis_flags' => [
                'prompt_injection',
            ],
        ]);

    $snapshot = app(
        CreateProjectContextSnapshot::class,
    )->handle(
        organizationId: $organization->id,
        projectId: $project->id,
    );

    return compact(
        'organization',
        'version',
        'snapshot',
    );
}

test(
    'provider context uses verified immutable content and redacts secrets',
    function (): void {
        [
            'version' => $version,
            'snapshot' => $snapshot,
        ] = providerBoundContextFixture();

        $context = app(
            BuildProviderBoundDocumentContext::class,
        )->handle($snapshot);

        expect($context)
            ->toHaveCount(1)
            ->and($context[0]['content'])
            ->toBe('[REDACTED] [REDACTED]')
            ->and(
                $context[0]['checksum_sha256'],
            )
            ->toBe($version->checksum_sha256)
            ->and($context[0]['safety_flags'])
            ->toBe(['prompt_injection'])
            ->and($version->fresh()->parsed_content)
            ->toContain('super-secret');
    },
);

test(
    'provider dispatch fails when parsed database content no longer matches the snapshot',
    function (): void {
        [
            'version' => $version,
            'snapshot' => $snapshot,
        ] = providerBoundContextFixture();

        /*
         * Use the query builder intentionally to simulate privileged database
         * drift that bypasses Eloquent model events.
         */
        DB::table('document_versions')
            ->where('id', $version->id)
            ->update([
                'parsed_content' => 'Drifted parsed content.',
                'updated_at' => now(),
            ]);

        expect(
            fn (): array => app(
                BuildProviderBoundDocumentContext::class,
            )->handle($snapshot),
        )->toThrow(
            DocumentContextIntegrityException::class,
            sprintf(
                'Document version %d failed parsed content checksum verification.',
                $version->id,
            ),
        );
    },
);

test(
    'provider dispatch fails when the immutable artifact is missing',
    function (): void {
        [
            'version' => $version,
            'snapshot' => $snapshot,
        ] = providerBoundContextFixture();

        $entry =
            $snapshot->approved_document_versions[0];

        Storage::disk(
            $entry['parsed_content_storage_disk'],
        )->delete(
            $entry['parsed_content_storage_path'],
        );

        expect(
            fn (): array => app(
                BuildProviderBoundDocumentContext::class,
            )->handle($snapshot),
        )->toThrow(
            DocumentContextIntegrityException::class,
            sprintf(
                'The immutable content artifact for document version %d is missing.',
                $version->id,
            ),
        );
    },
);

test(
    'provider dispatch fails when immutable artifact bytes are changed',
    function (): void {
        [
            'version' => $version,
            'snapshot' => $snapshot,
        ] = providerBoundContextFixture();

        $entry =
            $snapshot->approved_document_versions[0];

        Storage::disk(
            $entry['parsed_content_storage_disk'],
        )->put(
            $entry['parsed_content_storage_path'],
            'Tampered artifact.',
        );

        expect(
            fn (): array => app(
                BuildProviderBoundDocumentContext::class,
            )->handle($snapshot),
        )->toThrow(
            DocumentContextIntegrityException::class,
            sprintf(
                'The immutable content artifact for document version %d failed integrity verification.',
                $version->id,
            ),
        );
    },
);

test(
    'provider dispatch fails when an expected version is missing',
    function (): void {
        [
            'version' => $version,
            'snapshot' => $snapshot,
        ] = providerBoundContextFixture();

        DB::table('document_versions')
            ->where('id', $version->id)
            ->delete();

        expect(
            fn (): array => app(
                BuildProviderBoundDocumentContext::class,
            )->handle($snapshot),
        )->toThrow(
            DocumentContextIntegrityException::class,
            sprintf(
                'Expected document version %d is unavailable.',
                $version->id,
            ),
        );
    },
);

test(
    'the approved provider-bound secret corpus is redacted',
    function (string $secret): void {
        $result = app(
            RedactProviderBoundDocumentContext::class,
        )->handle($secret);

        expect($result)
            ->toContain('[REDACTED]')
            ->not->toContain($secret);
    },
)->with([
    'GitHub classic token' => [
        'ghp_abcdefghijklmnopqrstuvwxyz1234567890',
    ],
    'GitHub fine-grained token' => [
        'github_pat_11AA22BB33CC44DD55EE66FF77GG88HH',
    ],
    'OpenAI project token' => [
        'sk-proj-abcdefghijklmnopqrstuvwxyz123456',
    ],
    'AWS temporary access key' => [
        'ASIAABCDEFGHIJKLMNOP',
    ],
    'bearer credential' => [
        'Bearer abcdefghijklmnopqrstuvwxyz123456',
    ],
    'JSON web token' => [
        'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.signature123',
    ],
    'credentialed PostgreSQL URL' => [
        'postgresql://application:database-secret@db.example.com/app',
    ],
    'private key block' => [
        <<<'KEY'
-----BEGIN PRIVATE KEY-----
super-sensitive-private-key-material
-----END PRIVATE KEY-----
KEY,
    ],
    'named token assignment' => [
        'token=super-secret-provider-value',
    ],
]);

test(
    'non-secret lookalikes remain unchanged',
    function (string $content): void {
        $result = app(
            RedactProviderBoundDocumentContext::class,
        )->handle($content);

        expect($result)->toBe($content);
    },
)->with([
    'short GitHub value' => [
        'ghp_short',
    ],
    'short fine-grained value' => [
        'github_pat_short',
    ],
    'non-credential bearer text' => [
        'Bearer public',
    ],
    'database URL without credentials' => [
        'postgresql://db.example.com/app',
    ],
    'public key block' => [
        <<<'KEY'
-----BEGIN PUBLIC KEY-----
public-material
-----END PUBLIC KEY-----
KEY,
    ],
]);

test(
    'approved custom patterns participate in atomic redaction',
    function (): void {
        config()->set(
            'document-context-redaction.custom_patterns',
            [
                [
                    'id' => 'organization_internal_credential',
                    'expression' => '/\bACME-CREDENTIAL-[A-Z0-9]{12}\b/',
                ],
            ],
        );

        $result = app(
            RedactProviderBoundDocumentContext::class,
        )->handle(
            'ACME-CREDENTIAL-ABCDEF123456',
        );

        expect($result)->toBe('[REDACTED]');
    },
);

test(
    'invalid redaction configuration blocks the outbound boundary before a gateway can be called',
    function (): void {
        [
            'snapshot' => $snapshot,
        ] = providerBoundContextFixture();

        config()->set(
            'document-context-redaction.patterns',
            [
                [
                    'id' => 'invalid_regex',
                    'expression' => '/[unterminated/',
                ],
            ],
        );

        config()->set(
            'document-context-redaction.custom_patterns',
            [],
        );

        Log::spy();

        $gatewayCalled = false;

        try {
            $context = app(
                BuildProviderBoundDocumentContext::class,
            )->handle($snapshot);

            /*
             * This assignment represents the next outbound gateway operation.
             * It must remain unreachable after redaction failure.
             */
            $gatewayCalled = true;

            unset($context);

            $this->fail(
                'Expected provider-bound context generation to fail.',
            );
        } catch (ProviderBoundRedactionException $exception) {
            expect($exception->errorCode())
                ->toBe(
                    ProviderBoundRedactionException::INVALID_CONFIGURATION,
                )
                ->and($exception->patternId())
                ->toBe('invalid_regex')
                ->and($exception->getMessage())
                ->not->toContain('super-secret')
                ->not->toContain('/[unterminated/');
        }

        expect($gatewayCalled)->toBeFalse();

        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(
                function (
                    string $message,
                    array $context,
                ): bool {
                    $serializedContext = json_encode(
                        $context,
                        JSON_THROW_ON_ERROR,
                    );

                    return $message
                        === 'document.provider_context_redaction_blocked'
                        && $context['error_code']
                        === ProviderBoundRedactionException::INVALID_CONFIGURATION
                        && $context['pattern_id'] === 'invalid_regex'
                        && ! str_contains(
                            $serializedContext,
                            'super-secret',
                        )
                        && ! str_contains(
                            $serializedContext,
                            '/[unterminated/',
                        );
                },
            );
    },
);

test(
    'runtime PCRE failure blocks redaction without logging source content',
    function (): void {
        config()->set(
            'document-context-redaction.patterns',
            [
                [
                    'id' => 'utf8_runtime_failure',
                    'expression' => '/secret/u',
                ],
            ],
        );

        config()->set(
            'document-context-redaction.custom_patterns',
            [],
        );

        Log::spy();

        $invalidUtf8Content = "secret-\xB1";

        try {
            app(
                RedactProviderBoundDocumentContext::class,
            )->handle($invalidUtf8Content);

            $this->fail(
                'Expected runtime PCRE redaction to fail.',
            );
        } catch (ProviderBoundRedactionException $exception) {
            expect($exception->errorCode())
                ->toBe(
                    ProviderBoundRedactionException::EXECUTION_FAILED,
                )
                ->and($exception->patternId())
                ->toBe('utf8_runtime_failure')
                ->and($exception->pcreErrorCode())
                ->not->toBe(PREG_NO_ERROR)
                ->and($exception->getMessage())
                ->not->toContain('secret');
        }

        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(
                function (
                    string $message,
                    array $context,
                ): bool {
                    $serializedContext = json_encode(
                        $context,
                        JSON_THROW_ON_ERROR,
                    );

                    return $message
                        === 'document.provider_context_redaction_blocked'
                        && $context['error_code']
                        === ProviderBoundRedactionException::EXECUTION_FAILED
                        && $context['pattern_id']
                        === 'utf8_runtime_failure'
                        && ! str_contains(
                            $serializedContext,
                            'secret',
                        );
                },
            );
    },
);
