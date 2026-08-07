<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;

/**
 * Create an organization owner for one project.
 */
function documentScreenOwner(Project $project): User
{
    $user = User::factory()->create();

    OrganizationMembership::factory()
        ->owner()
        ->for($project->organization)
        ->for($user)
        ->create();

    return $user;
}

test('project overview exposes document center navigation', function (): void {
    $project = Project::factory()->create();
    $user = documentScreenOwner($project);

    $documentsUrl = route(
        'organizations.projects.documents.index',
        [
            'organization' => $project->organization,
            'project' => $project,
        ],
    );

    $this->actingAs($user)
        ->get(route(
            'organizations.projects.show',
            [
                'organization' => $project->organization,
                'project' => $project,
            ],
        ))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('projects/show')
            ->where('documentsUrl', $documentsUrl));
});

test('authorized members receive document upload capability', function (): void {
    $project = Project::factory()->create();
    $user = documentScreenOwner($project);

    $this->actingAs($user)
        ->get(route(
            'organizations.projects.documents.index',
            [
                'organization' => $project->organization,
                'project' => $project,
            ],
        ))
        ->assertInertia(fn ($page) => $page
            ->component('documents/index')
            ->where('permissions.upload', true)
            ->where(
                'urls.store',
                route(
                    'organizations.projects.documents.store',
                    [
                        'organization' => $project->organization,
                        'project' => $project,
                    ],
                ),
            ));
});

test('read only viewers never receive document action capabilities', function (): void {
    $project = Project::factory()->create();
    $viewer = User::factory()->create();

    OrganizationMembership::factory()
        ->viewer()
        ->for($project->organization)
        ->for($viewer)
        ->create();

    $document = Document::factory()
        ->for($project)
        ->create();

    DocumentVersion::factory()
        ->classified()
        ->for($document)
        ->create();

    $this->actingAs($viewer)
        ->get(route(
            'organizations.projects.documents.index',
            [
                'organization' => $project->organization,
                'project' => $project,
            ],
        ))
        ->assertInertia(fn ($page) => $page
            ->where('permissions.upload', false)
            ->where('urls.store', null));

    $this->actingAs($viewer)
        ->get(route(
            'organizations.projects.documents.show',
            [
                'organization' => $project->organization,
                'project' => $project,
                'document' => $document,
            ],
        ))
        ->assertInertia(fn ($page) => $page
            ->where('document.versions.0.actions.approve', null)
            ->where('document.versions.0.actions.reject', null)
            ->where('document.versions.0.actions.replacement', null)
            ->where('document.versions.0.actions.retry', null));
});

test('document detail capabilities follow lifecycle eligibility', function (): void {
    $project = Project::factory()->create();
    $user = documentScreenOwner($project);

    $document = Document::factory()
        ->for($project)
        ->create();

    $reviewVersion = DocumentVersion::factory()
        ->classified()
        ->for($document)
        ->create([
            'version' => 1,
        ]);

    $approvedVersion = DocumentVersion::factory()
        ->approved()
        ->for($document)
        ->create([
            'version' => 2,
        ]);

    $failedVersion = DocumentVersion::factory()
        ->analysisFailed()
        ->for($document)
        ->create([
            'version' => 3,
        ]);

    $this->actingAs($user)
        ->get(route(
            'organizations.projects.documents.show',
            [
                'organization' => $project->organization,
                'project' => $project,
                'document' => $document,
            ],
        ))
        ->assertInertia(fn ($page) => $page
            /*
             * Versions are sorted newest first.
             */
            ->where('document.versions.0.id', $failedVersion->id)
            ->where(
                'document.versions.0.actions.retry',
                route(
                    'organizations.projects.documents.versions.retry',
                    [
                        'organization' => $project->organization,
                        'project' => $project,
                        'document' => $document,
                        'version' => $failedVersion,
                    ],
                ),
            )
            ->where('document.versions.0.actions.approve', null)
            ->where(
                'document.versions.0.failure.message',
                'The document analysis could not be completed.',
            )
            ->where('document.versions.1.id', $approvedVersion->id)
            ->where(
                'document.versions.1.actions.replacement',
                route(
                    'organizations.projects.documents.versions.replacement.store',
                    [
                        'organization' => $project->organization,
                        'project' => $project,
                        'document' => $document,
                        'version' => $approvedVersion,
                    ],
                ),
            )
            ->where('document.versions.1.actions.approve', null)
            ->where('document.versions.2.id', $reviewVersion->id)
            ->where(
                'document.versions.2.actions.approve',
                route(
                    'organizations.projects.documents.versions.approve',
                    [
                        'organization' => $project->organization,
                        'project' => $project,
                        'document' => $document,
                        'version' => $reviewVersion,
                    ],
                ),
            )
            ->where(
                'document.versions.2.actions.reject',
                route(
                    'organizations.projects.documents.versions.reject',
                    [
                        'organization' => $project->organization,
                        'project' => $project,
                        'document' => $document,
                        'version' => $reviewVersion,
                    ],
                ),
            )
            ->where('document.versions.2.actions.retry', null));
});
