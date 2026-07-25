<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\OrganizationRole;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

/**
 * Creates deterministic browser-test data for the document-center workflow.
 */
final class DocumentCenterE2ESeeder extends Seeder
{
    /**
     * Seed an owner, project, and recoverable failed document version.
     *
     * The tenant root records are reused so the seeder remains safe when
     * Playwright reruns a failed test suite. Only document fixture records are
     * reset because they are the mutable state exercised by these browser tests.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new LogicException(
                'Document-center E2E data cannot be seeded in production.',
            );
        }

        DB::transaction(function (): void {
            $user = User::query()
                ->where('email', 'document-owner@example.test')
                ->first();

            if ($user === null) {
                $user = User::factory()->create([
                    'name' => 'Document Center Owner',
                    'email' => 'document-owner@example.test',
                    'password' => Hash::make('password'),
                ]);
            } else {
                $user->forceFill([
                    'name' => 'Document Center Owner',
                    'email_verified_at' => now(),
                    'password' => Hash::make('password'),
                ])->save();
            }

            $organization = Organization::query()
                ->where('slug', 'e2e-document-center')
                ->first();

            if ($organization === null) {
                $organization = Organization::factory()->create([
                    'name' => 'E2E Document Center',
                    'slug' => 'e2e-document-center',
                ]);
            } else {
                $organization->forceFill([
                    'name' => 'E2E Document Center',
                ])->save();
            }

            $project = Project::query()
                ->where('organization_id', $organization->getKey())
                ->where('slug', 'document-workflow-project')
                ->first();

            if ($project === null) {
                $project = Project::factory()->create([
                    'organization_id' => $organization->getKey(),
                    'name' => 'Document Workflow Project',
                    'slug' => 'document-workflow-project',
                ]);
            } else {
                $project->forceFill([
                    'name' => 'Document Workflow Project',
                ])->save();
            }

            OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->getKey(),
                    'user_id' => $user->getKey(),
                ],
                [
                    'role' => OrganizationRole::Owner,
                ],
            );

            /*
             * Reset versions before documents because both foreign keys use
             * RESTRICT deletion semantics. Clear self-references first so
             * superseded version chains cannot block deletion.
             */
            $documentIds = Document::query()
                ->where('project_id', $project->getKey())
                ->pluck('id');

            if ($documentIds->isNotEmpty()) {
                DocumentVersion::query()
                    ->whereIn('document_id', $documentIds)
                    ->update([
                        'supersedes_document_version_id' => null,
                    ]);

                DocumentVersion::query()
                    ->whereIn('document_id', $documentIds)
                    ->delete();

                Document::query()
                    ->whereIn('id', $documentIds)
                    ->delete();
            }

            $failedDocument = Document::factory()
                ->for($project)
                ->create([
                    'title' => 'Recoverable architecture document',
                    'document_class' => 'architecture',
                ]);

            DocumentVersion::factory()
                ->analysisFailed()
                ->for($failedDocument)
                ->create([
                    'version' => 1,
                    'original_filename' => 'recoverable-architecture.md',
                ]);
        });
    }
}
