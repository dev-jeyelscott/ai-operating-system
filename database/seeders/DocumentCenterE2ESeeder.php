<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Creates deterministic browser-test data for the document-center workflow.
 */
final class DocumentCenterE2ESeeder extends Seeder
{
    /**
     * Seed an owner, project, and recoverable failed document version.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new LogicException(
                'Document-center E2E data cannot be seeded in production.',
            );
        }

        DB::transaction(function (): void {
            Organization::query()
                ->where('slug', 'e2e-document-center')
                ->first()
                ?->delete();

            User::query()
                ->where('email', 'document-owner@example.test')
                ->delete();

            $user = User::factory()->create([
                'name' => 'Document Center Owner',
                'email' => 'document-owner@example.test',
            ]);

            $organization = Organization::factory()->create([
                'name' => 'E2E Document Center',
                'slug' => 'e2e-document-center',
            ]);

            $project = Project::factory()->create([
                'organization_id' => $organization->id,
                'name' => 'Document Workflow Project',
                'slug' => 'document-workflow-project',
            ]);

            OrganizationMembership::factory()
                ->owner()
                ->for($organization)
                ->for($user)
                ->create();

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
