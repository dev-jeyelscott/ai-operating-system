<?php

declare(strict_types=1);

namespace App\Rules\Documents;

use App\Application\Documents\DocumentUploadInspector;
use App\Application\Documents\Exceptions\DocumentProcessingException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Applies the shared document security inspection during validation.
 */
final readonly class SafeProjectDocumentUpload implements ValidationRule
{
    /**
     * Create the upload validation rule.
     */
    public function __construct(
        private DocumentUploadInspector $uploadInspector,
    ) {}

    /**
     * Reject any upload that fails the application security boundary.
     */
    public function validate(
        string $attribute,
        mixed $value,
        Closure $fail,
    ): void {
        if (! $value instanceof UploadedFile) {
            return;
        }

        try {
            $this->uploadInspector->inspect($value);
        } catch (DocumentProcessingException $exception) {
            $fail($exception->getMessage());
        } catch (Throwable) {
            /*
             * Do not expose temporary paths, storage details, or exception
             * messages from unexpected inspection failures.
             */
            $fail('The document could not be inspected securely.');
        }
    }
}
