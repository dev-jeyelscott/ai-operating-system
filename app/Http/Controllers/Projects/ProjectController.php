<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Application\Projects\CreateProject;
use App\Application\Projects\UpdateProject;
use App\Domain\Projects\ProjectType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Handles organization-scoped project CRUD delivery concerns.
 */
final class ProjectController extends Controller
{
    /**
     * List current or archived projects owned by the organization.
     */
    public function index(
        Request $request,
        Organization $organization,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $showArchived = $request->boolean('archived');

        $query = $organization
            ->projects()
            ->orderByDesc('updated_at');

        if ($showArchived) {
            $query->archived();
        } else {
            $query->unarchived();
        }

        $projects = $query
            ->paginate(12)
            ->withQueryString()
            ->through(
                fn (Project $project): array => $this->serializeProject(
                    $project,
                ),
            );

        return Inertia::render('projects/index', [
            'organization' => $this->serializeOrganization($organization),
            'projects' => $projects,
            'filters' => [
                'archived' => $showArchived,
            ],
            'filterUrls' => [
                'current' => route('organizations.projects.index', [
                    'organization' => $organization,
                ]),
                'archived' => route('organizations.projects.index', [
                    'organization' => $organization,
                    'archived' => 1,
                ]),
            ],
            'can' => [
                'create' => $user->can(
                    'createProject',
                    $organization,
                ),
            ],
        ]);
    }

    /**
     * Render the project creation form.
     */
    public function create(Organization $organization): Response
    {
        return Inertia::render('projects/create', [
            'organization' => $this->serializeOrganization($organization),
            'projectTypes' => $this->projectTypeOptions(),
        ]);
    }

    /**
     * Create a new project inside the organization.
     */
    public function store(
        StoreProjectRequest $request,
        Organization $organization,
        CreateProject $createProject,
    ): RedirectResponse {
        $validated = $request->validated();

        $project = $createProject->handle(
            organization: $organization,
            name: (string) $validated['name'],
            description: is_string($validated['description'] ?? null)
                ? $validated['description']
                : null,
            projectType: ProjectType::from(
                (string) $validated['project_type'],
            ),
        );

        return to_route('organizations.projects.show', [
            'organization' => $organization,
            'project' => $project,
        ])->with('status', 'project-created');
    }

    /**
     * Render project details and authorized operations.
     */
    public function show(
        Request $request,
        Organization $organization,
        Project $project,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return Inertia::render('projects/show', [
            'organization' => $this->serializeOrganization($organization),
            'project' => $this->serializeProject($project),
            'permissions' => [
                'update' => $user->can('update', $project),
                'archive' => $user->can('archive', $project),
                'restore' => $user->can('restore', $project),
            ],
        ]);
    }

    /**
     * Render the project metadata editing form.
     */
    public function edit(
        Organization $organization,
        Project $project,
    ): Response {
        return Inertia::render('projects/edit', [
            'organization' => $this->serializeOrganization($organization),
            'project' => $this->serializeProject($project),
            'projectTypes' => $this->projectTypeOptions(),
        ]);
    }

    /**
     * Update mutable project metadata.
     */
    public function update(
        UpdateProjectRequest $request,
        Organization $organization,
        Project $project,
        UpdateProject $updateProject,
    ): RedirectResponse {
        $validated = $request->validated();

        $project = $updateProject->handle(
            project: $project,
            name: (string) $validated['name'],
            description: is_string($validated['description'] ?? null)
                ? $validated['description']
                : null,
            projectType: ProjectType::from(
                (string) $validated['project_type'],
            ),
        );

        return to_route('organizations.projects.show', [
            'organization' => $organization,
            'project' => $project,
        ])->with('status', 'project-updated');
    }

    /**
     * Convert an organization model into a minimal public payload.
     *
     * @return array{id: int, name: string, slug: string}
     */
    private function serializeOrganization(
        Organization $organization,
    ): array {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
        ];
    }

    /**
     * Convert a project model into an explicit Inertia payload.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     slug: string,
     *     description: string|null,
     *     projectType: array{value: string, label: string},
     *     status: array{value: string, label: string},
     *     archivedAt: string|null,
     *     createdAt: string|null,
     *     updatedAt: string|null
     * }
     */
    private function serializeProject(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'description' => $project->description,
            'projectType' => [
                'value' => $project->project_type->value,
                'label' => Str::headline($project->project_type->value),
            ],
            'status' => [
                'value' => $project->status->value,
                'label' => Str::headline($project->status->value),
            ],
            'archivedAt' => $project->archived_at?->toIso8601String(),
            'createdAt' => $project->created_at?->toIso8601String(),
            'updatedAt' => $project->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Return supported project types for form controls.
     *
     * @return list<array{value: string, label: string}>
     */
    private function projectTypeOptions(): array
    {
        return array_map(
            static fn (ProjectType $type): array => [
                'value' => $type->value,
                'label' => Str::headline($type->value),
            ],
            ProjectType::cases(),
        );
    }
}
