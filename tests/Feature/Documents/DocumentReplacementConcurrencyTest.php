<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Application\Documents\StoreReplacementDocumentVersion;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Verifies replacement allocation against the real PostgreSQL lock behavior.
 */
final class DocumentReplacementConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_uploads_allocate_unique_monotonic_versions(): void
    {
        $organization = Organization::factory()->create();

        $project = Project::factory()
            ->for($organization)
            ->create();

        $document = Document::factory()
            ->for($project)
            ->create();

        $approved = DocumentVersion::factory()
            ->approved()
            ->for($document)
            ->create([
                'version' => 1,
            ]);

        $allocatedVersions = Concurrency::driver(
            'process',
        )->run([
            static fn (): int => self::storeReplacement(
                organizationId: $organization->id,
                projectId: $project->id,
                documentId: $document->id,
                approvedVersionId: $approved->id,
                filename: 'replacement-a.txt',
                contents: 'Replacement A',
            ),
            static fn (): int => self::storeReplacement(
                organizationId: $organization->id,
                projectId: $project->id,
                documentId: $document->id,
                approvedVersionId: $approved->id,
                filename: 'replacement-b.txt',
                contents: 'Replacement B',
            ),
        ]);

        sort($allocatedVersions);

        self::assertSame(
            [2, 3],
            $allocatedVersions,
        );

        self::assertSame(
            [1, 2, 3],
            $document
                ->versions()
                ->orderBy('version')
                ->pluck('version')
                ->map(
                    static fn (mixed $version): int => (int) $version,
                )
                ->all(),
        );

        self::assertSame(
            3,
            $document
                ->versions()
                ->distinct()
                ->count('storage_path'),
        );

        self::assertSame(
            1,
            $document
                ->versions()
                ->where('status', 'approved')
                ->count(),
        );

        $document
            ->versions()
            ->where('id', '<>', $approved->id)
            ->get()
            ->each(
                static function (
                    DocumentVersion $version,
                ): void {
                    Storage::disk(
                        $version->storage_disk,
                    )->delete(
                        $version->storage_path,
                    );
                },
            );
    }

    /**
     * Store one replacement from an isolated process.
     */
    private static function storeReplacement(
        int $organizationId,
        int $projectId,
        int $documentId,
        int $approvedVersionId,
        string $filename,
        string $contents,
    ): int {
        config()->set(
            'filesystems.artifact',
            'local',
        );

        $temporaryPath = tempnam(
            sys_get_temp_dir(),
            'aios-replacement-',
        );

        if (! is_string($temporaryPath)) {
            throw new \RuntimeException(
                'Unable to create a temporary replacement file.',
            );
        }

        file_put_contents(
            $temporaryPath,
            $contents,
        );

        try {
            $uploadedFile = new UploadedFile(
                path: $temporaryPath,
                originalName: $filename,
                mimeType: 'text/plain',
                error: null,
                test: true,
            );

            $replacement = app(
                StoreReplacementDocumentVersion::class,
            )->handle(
                organization: Organization::query()
                    ->findOrFail($organizationId),
                project: Project::query()
                    ->findOrFail($projectId),
                document: Document::query()
                    ->findOrFail($documentId),
                approvedVersion: DocumentVersion::query()
                    ->findOrFail($approvedVersionId),
                uploadedFile: $uploadedFile,
            );

            return $replacement->version;
        } finally {
            @unlink($temporaryPath);
        }
    }
}
