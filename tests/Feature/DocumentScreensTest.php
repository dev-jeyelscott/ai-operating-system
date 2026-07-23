<?php

declare(strict_types=1);
use App\Models\Document;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;

test('members see tenant-scoped document inventory', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    OrganizationMembership::factory()->owner()->for($project->organization)->for($user)->create();
    $document = Document::factory()->for($project)->create();
    $this->actingAs($user)
        ->get(route('organizations.projects.documents.index', ['organization' => $project->organization, 'project' => $project]))
        ->assertInertia(fn ($page) => $page
            ->component('documents/index')
            ->has('documents', 1)
            ->where('documents.0.id', $document->id)
            ->where('documents.0.url', route('organizations.projects.documents.show', [
                'organization' => $project->organization,
                'project' => $project,
                'document' => $document,
            ])));
});
