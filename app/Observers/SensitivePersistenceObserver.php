<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Artifact;
use App\Models\Evidence;
use App\Models\ExecutionAttempt;
use App\Models\NotificationEvent;
use App\Support\Security\SensitiveValueRedactor;
use Illuminate\Database\Eloquent\Model;

/**
 * Enforces redaction rules at sensitive persistence boundaries.
 */
final readonly class SensitivePersistenceObserver
{
    /**
     * Inject the application-wide redactor.
     */
    public function __construct(
        private SensitiveValueRedactor $redactor,
    ) {}

    /**
     * Reject immutable artifacts or evidence containing sensitive content.
     */
    public function creating(Model $model): void
    {
        if ($model instanceof Artifact) {
            $this->assertArtifactIsClean($model);

            return;
        }

        if ($model instanceof Evidence) {
            $this->assertEvidenceIsClean($model);
        }
    }

    /**
     * Sanitize mutable error and notification fields before every write.
     */
    public function saving(Model $model): void
    {
        if ($model instanceof NotificationEvent) {
            $this->sanitizeNotification($model);

            return;
        }

        if ($model instanceof ExecutionAttempt) {
            $this->sanitizeExecutionAttempt($model);
        }
    }

    /**
     * Reject an artifact unless every free-form field is already safe.
     */
    private function assertArtifactIsClean(
        Artifact $artifact,
    ): void {
        $this->redactor->assertClean(
            [
                'name' => $artifact->name,
                'storage_path' => $artifact->storage_path,
                'external_reference' => $artifact
                    ->external_reference,
                'assumptions' => (array) $artifact->assumptions,
                'metadata' => (array) $artifact->metadata,
                'idempotency_key' => $artifact
                    ->idempotency_key,
            ],
            'artifact content',
        );
    }

    /**
     * Reject evidence unless every free-form field is already safe.
     */
    private function assertEvidenceIsClean(
        Evidence $evidence,
    ): void {
        $this->redactor->assertClean(
            [
                'source_reference' => $evidence
                    ->source_reference,
                'claims' => (array) $evidence->claims,
                'verification_method' => $evidence
                    ->verification_method,
                'rejection_reason' => $evidence
                    ->rejection_reason,
                'metadata' => (array) $evidence->metadata,
                'provenance_key' => $evidence
                    ->provenance_key,
            ],
            'evidence content',
        );
    }

    /**
     * Redact notification display content before persistence.
     */
    private function sanitizeNotification(
        NotificationEvent $notification,
    ): void {
        $actionUrl = is_string($notification->action_url)
            ? $notification->action_url
            : null;

        $safeActionUrl = $actionUrl === null
            ? null
            : $this->redactor->message($actionUrl);

        /*
         * A URL changed by redaction is no longer a trustworthy navigation
         * target. The server-owned notification open command remains available.
         */
        if (
            $actionUrl !== null
            && $safeActionUrl !== $actionUrl
        ) {
            $safeActionUrl = null;
        }

        $notification->forceFill([
            'title' => $this->redactor->message(
                (string) $notification->title,
            ),
            'message' => $this->redactor->message(
                (string) $notification->message,
            ),
            'action_url' => $safeActionUrl,
            'data' => $this->redactor->redact(
                is_array($notification->data)
                    ? $notification->data
                    : [],
            ),
        ]);
    }

    /**
     * Redact durable provider and infrastructure errors.
     */
    private function sanitizeExecutionAttempt(
        ExecutionAttempt $attempt,
    ): void {
        if (
            ! $attempt->isDirty('error_message')
            || ! is_string($attempt->error_message)
        ) {
            return;
        }

        $attempt->forceFill([
            'error_message' => $this->redactor->message(
                $attempt->error_message,
            ),
        ]);
    }
}
