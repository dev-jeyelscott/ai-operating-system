<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Audit\Data\AuditContext;
use App\Application\Documents\Contracts\DocumentParser;
use App\Application\Documents\Exceptions\DocumentProcessingException;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditEventType;
use App\Domain\Documents\DocumentClassification;
use App\Domain\Documents\DocumentProcessingFailureCode;
use App\Domain\Documents\DocumentStatus;
use App\Jobs\AnalyzeDocumentVersionJob;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Parses scan-approved document versions through the active parser.
 */
final readonly class ParseDocumentVersion
{
    /**
     * Create the document-parsing application service.
     */
    public function __construct(
        private DocumentParser $documentParser,
        private RecordDocumentLifecycleEvent $events,
        private TransactionManager $transactions,
    ) {}

    /**
     * Parse one scan-approved document version idempotently.
     *
     * Permanent content failures are recorded immediately. Transient
     * infrastructure failures are rethrown for bounded queue retry.
     *
     * @throws Throwable
     */
    public function handle(
        int $id,
        ?AuditContext $auditContext = null,
    ): void {
        $auditContext ??= AuditContext::system(
            actorId: 'document-parse-worker',
        );

        $version = $this->transactions->run(
            function () use ($id, $auditContext): ?DocumentVersion {
                $version = DocumentVersion::query()
                    ->lockForUpdate()
                    ->findOrFail($id);

                if (! in_array($version->status, [
                    DocumentStatus::ScanApproved,
                    DocumentStatus::Parsing,
                ], true)) {
                    return null;
                }

                if (! $this->documentParser->supports(
                    $version->media_type,
                )) {
                    $this->markUnsupportedMediaType(
                        version: $version,
                        auditContext: $auditContext,
                    );

                    return null;
                }

                if ($version->status === DocumentStatus::ScanApproved) {
                    $version->beginParsing(
                        $this->documentParser->name(),
                        $this->documentParser->version(),
                    );

                    $this->events->version(
                        version: $version,
                        eventType: AuditEventType::DocumentParseStarted,
                        context: $auditContext,
                        metadata: [
                            'previous_status' => DocumentStatus::ScanApproved->value,
                            'new_status' => DocumentStatus::Parsing->value,
                            'parser_name' => $this->documentParser->name(),
                            'parser_version' => $this->documentParser->version(),
                        ],
                    );

                    return $version->fresh();
                }

                return $version;
            },
        );

        if (! $version instanceof DocumentVersion) {
            return;
        }

        Log::info('document.parsing_started', [
            'document_version_id' => $id,
            'parser_name' => $this->documentParser->name(),
            'parser_version' => $this->documentParser->version(),
        ]);

        try {
            $parsed = $this->documentParser->parse($version);
        } catch (DocumentProcessingException $exception) {
            if ($exception->retryable) {
                throw $exception;
            }

            $this->recordPermanentFailure(
                id: $id,
                exception: $exception,
                auditContext: $auditContext,
            );

            return;
        }

        $analysisPending = $this->transactions->run(
            function () use ($id, $parsed, $auditContext): bool {
                $version = DocumentVersion::query()
                    ->lockForUpdate()
                    ->findOrFail($id);

                if ($version->status !== DocumentStatus::Parsing) {
                    return false;
                }

                $version->forceFill([
                    'status' => DocumentStatus::AnalysisPending,
                    'classification' => DocumentClassification::Unclassified,
                    'parser_name' => $parsed->parserName,
                    'parser_version' => $parsed->parserVersion,
                    'parsed_at' => now(),
                    'parsed_content' => $parsed->content,
                    'analyzer_name' => null,
                    'analyzer_version' => null,
                    'analysis_seed' => null,
                    'analysis_started_at' => null,
                    'analysis_completed_at' => null,
                    'analysis_summary' => null,
                    'analysis_conflicts' => [],
                    'analysis_gaps' => [],
                    'analysis_flags' => [],
                    'failure_code' => null,
                    'failure_message' => null,
                ])->save();

                $parsedEvent = $this->events->version(
                    version: $version,
                    eventType: AuditEventType::DocumentParseCompleted,
                    context: $auditContext,
                    metadata: [
                        'previous_status' => DocumentStatus::Parsing->value,
                        'new_status' => DocumentStatus::AnalysisPending->value,
                        'parser_name' => $parsed->parserName,
                        'parser_version' => $parsed->parserVersion,
                    ],
                );

                AnalyzeDocumentVersionJob::dispatch(
                    documentVersionId: $version->id,
                    correlationId: $auditContext->correlationId,
                    causationId: $parsedEvent->eventId,
                    executionId: $auditContext->executionId,
                )->afterCommit();

                return true;
            },
        );

        if (! $analysisPending) {
            return;
        }

        Log::info('document.parsed', [
            'document_version_id' => $id,
            'next_status' => DocumentStatus::AnalysisPending->value,
        ]);
    }

    /**
     * Record terminal parser failure after queue attempts are exhausted.
     */
    public function markFailed(
        int $id,
        ?AuditContext $auditContext = null,
        ?Throwable $exception = null,
    ): void {
        $auditContext ??= AuditContext::system(
            actorId: 'document-parse-worker',
        );

        $failureCode = DocumentProcessingFailureCode::ParseFailed;
        $failureMessage = 'The document could not be parsed.';

        if ($exception instanceof DocumentProcessingException) {
            $failureCode = $exception->failureCode;
            $failureMessage = $exception->getMessage();
        }

        $this->transactions->run(function () use (
            $id,
            $auditContext,
            $failureCode,
            $failureMessage,
        ): void {
            $version = DocumentVersion::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($version->status !== DocumentStatus::Parsing) {
                return;
            }

            $version->forceFill([
                'status' => DocumentStatus::ParseFailed,
                'failure_code' => $failureCode->value,
                'failure_message' => $failureMessage,
            ])->save();

            $this->events->version(
                version: $version,
                eventType: AuditEventType::DocumentParseFailed,
                context: $auditContext,
                metadata: [
                    'previous_status' => DocumentStatus::Parsing->value,
                    'new_status' => DocumentStatus::ParseFailed->value,
                    'failure_code' => $failureCode->value,
                ],
            );
        });
    }

    /**
     * Record a deterministic parser failure without queue retry.
     */
    private function recordPermanentFailure(
        int $id,
        DocumentProcessingException $exception,
        AuditContext $auditContext,
    ): void {
        $this->transactions->run(function () use (
            $id,
            $exception,
            $auditContext,
        ): void {
            $version = DocumentVersion::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($version->status !== DocumentStatus::Parsing) {
                return;
            }

            $version->forceFill([
                'status' => DocumentStatus::ParseFailed,
                'failure_code' => $exception->failureCode->value,
                'failure_message' => $exception->getMessage(),
            ])->save();

            $this->events->version(
                version: $version,
                eventType: AuditEventType::DocumentParseFailed,
                context: $auditContext,
                metadata: [
                    'previous_status' => DocumentStatus::Parsing->value,
                    'new_status' => DocumentStatus::ParseFailed->value,
                    'failure_code' => $exception->failureCode->value,
                ],
            );
        });

        Log::warning('document.parsing_rejected', [
            'document_version_id' => $id,
            'failure_code' => $exception->failureCode->value,
        ]);
    }

    /**
     * Record unsupported media as an immediate permanent failure.
     */
    private function markUnsupportedMediaType(
        DocumentVersion $version,
        AuditContext $auditContext,
    ): void {
        $version->forceFill([
            'status' => DocumentStatus::ParseFailed,
            'parser_name' => null,
            'parser_version' => null,
            'parsing_started_at' => null,
            'parsed_at' => null,
            'parsed_content' => null,
            'failure_code' => DocumentProcessingFailureCode::UnsupportedMediaType->value,
            'failure_message' => sprintf(
                'No parser is registered for media type "%s". Supported media types: %s.',
                $version->media_type,
                implode(
                    ', ',
                    $this->documentParser->supportedMediaTypes(),
                ),
            ),
        ])->save();

        $this->events->version(
            version: $version,
            eventType: AuditEventType::DocumentParseFailed,
            context: $auditContext,
            metadata: [
                'previous_status' => DocumentStatus::ScanApproved->value,
                'new_status' => DocumentStatus::ParseFailed->value,
                'failure_code' => DocumentProcessingFailureCode::UnsupportedMediaType->value,
            ],
        );

        Log::warning('document.parsing_rejected', [
            'document_version_id' => $version->id,
            'media_type' => $version->media_type,
            'failure_code' => DocumentProcessingFailureCode::UnsupportedMediaType->value,
        ]);
    }
}
