<?php

use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Projects\ProjectSetupStep;
use App\Http\Controllers\Audit\ProjectAuditTimelineController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Development\DevelopmentExecutionInspectorController;
use App\Http\Controllers\Development\DevelopmentQueueController;
use App\Http\Controllers\Documents\ProjectDocumentController;
use App\Http\Controllers\Documents\RetryDocumentVersionProcessingController;
use App\Http\Controllers\Documents\ReviewDocumentVersionController;
use App\Http\Controllers\Documents\StoreProjectDocumentController;
use App\Http\Controllers\Documents\StoreReplacementDocumentVersionController;
use App\Http\Controllers\Health\HealthController;
use App\Http\Controllers\Health\ReadinessController;
use App\Http\Controllers\Integrations\StoreProjectIntegrationCredentialController;
use App\Http\Controllers\Integrations\TestProjectNotionConnectionController;
use App\Http\Controllers\Organizations\OrganizationController;
use App\Http\Controllers\Organizations\OrganizationDashboardController;
use App\Http\Controllers\Organizations\SwitchCurrentOrganizationController;
use App\Http\Controllers\Planning\DecideNotionReconciliationConflictController;
use App\Http\Controllers\Planning\DecideRoadmapController;
use App\Http\Controllers\Planning\DeferNotionReconciliationConflictController;
use App\Http\Controllers\Planning\EditRoadmapController;
use App\Http\Controllers\Planning\PublishRoadmapToNotionController;
use App\Http\Controllers\Planning\ReconcileNotionRoadmapController;
use App\Http\Controllers\Planning\RegenerateRoadmapController;
use App\Http\Controllers\Planning\RetainInternalNotionConflictController;
use App\Http\Controllers\Planning\RetryFailedNotionPublicationController;
use App\Http\Controllers\Planning\RoadmapController;
use App\Http\Controllers\Projects\ArchiveProjectController;
use App\Http\Controllers\Projects\ProjectConfigurationController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\ProjectSetupController;
use App\Http\Controllers\Projects\RestoreProjectController;
use App\Http\Controllers\QualityAssurance\QualityAssuranceReportController;
use App\Http\Controllers\QualityAssurance\SubmitSimulatedMergeDecisionController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'auth.session', 'verified'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)
        ->name('dashboard');

    Route::post('/organizations', OrganizationController::class)
        ->name('organizations.store');

    Route::prefix('/organizations/{organization}')
        ->name('organizations.')
        ->scopeBindings()
        ->group(function (): void {
            Route::get('/dashboard', OrganizationDashboardController::class)
                ->can('view', 'organization')
                ->name('dashboard');

            Route::put('/current', SwitchCurrentOrganizationController::class)
                ->can('view', 'organization')
                ->name('current.update');

            Route::prefix('/projects')
                ->name('projects.')
                ->group(function (): void {
                    Route::controller(ProjectController::class)
                        ->group(function (): void {
                            Route::get('/', 'index')
                                ->can('view', 'organization')
                                ->name('index');

                            /*
                             * Define /create before /{project} so "create" is
                             * not interpreted as a project slug.
                             */
                            Route::get('/create', 'create')
                                ->can('createProject', 'organization')
                                ->name('create');

                            Route::post('/', 'store')
                                ->middleware('throttle:project-commands')
                                ->can('createProject', 'organization')
                                ->name('store');
                        });
                    /*
                     * Validate a Notion token, workspace, and database through
                     * the dedicated integration connection-test action.
                     */
                    Route::post(
                        '/{project}/integrations/notion/test',
                        TestProjectNotionConnectionController::class,
                    )
                        ->middleware('throttle:project-commands')
                        ->can('manageIntegrations', 'project')
                        ->name('integrations.notion.test');

                    Route::post(
                        '/{project}/documents',
                        StoreProjectDocumentController::class,
                    )
                        ->middleware('throttle:project-commands')
                        ->can('update', 'project')
                        ->name('documents.store');

                    Route::get('/{project}/documents', [ProjectDocumentController::class, 'index'])
                        ->can('view', 'project')->name('documents.index');
                    Route::get('/{project}/documents/{document}', [ProjectDocumentController::class, 'show'])
                        ->can('view', 'project')->name('documents.show');

                    Route::controller(ReviewDocumentVersionController::class)
                        ->prefix('/{project}/documents/{document}/versions/{version}')
                        ->middleware('throttle:project-commands')
                        ->can('update', 'project')
                        ->name('documents.versions.')
                        ->group(function (): void {
                            Route::post('/approve', 'approve')->name('approve');
                            Route::post('/reject', 'reject')->name('reject');
                        });

                    Route::post(
                        '/{project}/documents/{document}/versions/{version}/replacement',
                        StoreReplacementDocumentVersionController::class,
                    )
                        ->middleware('throttle:project-commands')
                        ->can('update', 'project')
                        ->name('documents.versions.replacement.store');

                    Route::post(
                        '/{project}/documents/{document}/versions/{version}/retry',
                        RetryDocumentVersionProcessingController::class,
                    )
                        ->middleware('throttle:project-commands')
                        ->can('update', 'project')
                        ->name('documents.versions.retry');

                    /*
                     * Store or rotate an encrypted provider credential.
                     */
                    Route::put(
                        '/{project}/integrations/{provider}/credential',
                        StoreProjectIntegrationCredentialController::class,
                    )
                        ->whereIn(
                            'provider',
                            IntegrationProvider::values(),
                        )
                        ->middleware('throttle:project-commands')
                        ->can('manageIntegrations', 'project')
                        ->name('integrations.credentials.store');

                    Route::controller(ProjectSetupController::class)
                        ->prefix('/{project}/setup')
                        ->name('setup.')
                        ->group(function (): void {
                            Route::get('/', 'start')
                                ->can('update', 'project')
                                ->name('start');

                            /*
                             * Every setup step must remain viewable, including
                             * Integrations and Review.
                             */
                            Route::get('/{step}', 'show')
                                ->whereIn(
                                    'step',
                                    ProjectSetupStep::values(),
                                )
                                ->can('update', 'project')
                                ->name('show');

                            /*
                             * Only directly persisted setup steps use the
                             * generic update endpoint. Integrations uses its
                             * dedicated connection-test action.
                             */
                            Route::put('/{step}', 'update')
                                ->whereIn(
                                    'step',
                                    ProjectSetupStep::directUpdateValues(),
                                )
                                ->middleware('throttle:project-commands')
                                ->can('update', 'project')
                                ->name('update');
                        });

                    Route::controller(ProjectConfigurationController::class)
                        ->group(function (): void {
                            /*
                            * Display read-only configuration metadata and completeness results.
                            */
                            Route::get('/{project}/settings', 'settings')
                                ->can('view', 'project')
                                ->name('settings.show');

                            /*
                            * Display safe integration and credential metadata.
                            */
                            Route::get('/{project}/integrations', 'integrations')
                                ->can('view', 'project')
                                ->name('integrations.index');
                        });

                    Route::get(
                        '/{project}/audit',
                        ProjectAuditTimelineController::class,
                    )
                        ->can('view', 'project')
                        ->name('audit.index');

                    Route::get('/{project}/development', DevelopmentQueueController::class)
                        ->can('view', 'project')
                        ->name('development.index');

                    Route::get('/{project}/development/executions/{execution}', DevelopmentExecutionInspectorController::class)
                        ->can('view', 'project')
                        ->name('development.executions.show');

                    Route::get(
                        '/{project}/quality-assurance',
                        QualityAssuranceReportController::class,
                    )
                        ->can('view', 'project')
                        ->name('quality-assurance.index');

                    Route::post(
                        '/{project}/quality-assurance/assessments/{assessment}/decisions',
                        SubmitSimulatedMergeDecisionController::class,
                    )
                        ->whereUlid('assessment')
                        ->middleware('throttle:project-commands')
                        ->can('approve', 'project')
                        ->name('quality-assurance.decisions.store');

                    Route::prefix('/{project}/roadmaps')
                        ->name('roadmaps.')
                        ->group(function (): void {
                            Route::get('/', [RoadmapController::class, 'index'])
                                ->can('view', 'project')
                                ->name('index');
                            Route::get('/{roadmap}', [RoadmapController::class, 'show'])
                                ->can('view', 'project')
                                ->name('show');
                            Route::get('/{roadmap}/phases/{phase}', [RoadmapController::class, 'phase'])
                                ->can('view', 'project')
                                ->name('phases.show');
                            Route::get('/{roadmap}/tasks/{task}', [RoadmapController::class, 'task'])
                                ->can('view', 'project')
                                ->name('tasks.show');
                            Route::post('/{roadmap}/edits', EditRoadmapController::class)
                                ->middleware('throttle:project-commands')
                                ->can('update', 'project')
                                ->name('edits.store');
                            Route::post('/{roadmap}/approve', [DecideRoadmapController::class, 'approve'])
                                ->middleware('throttle:project-commands')
                                ->can('approve', 'project')
                                ->name('approve');
                            Route::post('/{roadmap}/reject', [DecideRoadmapController::class, 'reject'])
                                ->middleware('throttle:project-commands')
                                ->can('approve', 'project')
                                ->name('reject');
                            Route::post('/{roadmap}/regenerate', RegenerateRoadmapController::class)
                                ->middleware('throttle:project-commands')
                                ->can('approve', 'project')
                                ->name('regenerate');
                            Route::post('/{roadmap}/notion/publish', PublishRoadmapToNotionController::class)
                                ->middleware('throttle:project-commands')
                                ->can('approve', 'project')
                                ->name('notion.publish');
                            Route::post('/{roadmap}/notion/retry', RetryFailedNotionPublicationController::class)
                                ->middleware('throttle:project-commands')
                                ->can('approve', 'project')
                                ->name('notion.retry');
                            Route::post('/{roadmap}/notion/reconcile', ReconcileNotionRoadmapController::class)
                                ->middleware('throttle:project-commands')
                                ->can('approve', 'project')
                                ->name('notion.reconcile');
                            Route::post('/notion/conflicts/{conflict}/accept-external', DecideNotionReconciliationConflictController::class)
                                ->middleware('throttle:project-commands')
                                ->can('approve', 'project')
                                ->name('notion.conflicts.accept-external');
                            Route::post('/notion/conflicts/{conflict}/retain-internal', RetainInternalNotionConflictController::class)
                                ->middleware('throttle:project-commands')
                                ->can('approve', 'project')
                                ->name('notion.conflicts.retain-internal');
                            Route::post('/notion/conflicts/{conflict}/defer', DeferNotionReconciliationConflictController::class)
                                ->middleware('throttle:project-commands')
                                ->can('approve', 'project')
                                ->name('notion.conflicts.defer');
                        });

                    Route::controller(ProjectController::class)
                        ->group(function (): void {
                            Route::get('/{project}', 'show')
                                ->can('view', 'project')
                                ->name('show');

                            Route::get('/{project}/edit', 'edit')
                                ->can('update', 'project')
                                ->name('edit');

                            Route::put('/{project}', 'update')
                                ->middleware('throttle:project-commands')
                                ->can('update', 'project')
                                ->name('update');
                        });

                    Route::put(
                        '/{project}/archive',
                        ArchiveProjectController::class,
                    )
                        ->middleware('throttle:project-commands')
                        ->can('archive', 'project')
                        ->name('archive');

                    Route::put(
                        '/{project}/restore',
                        RestoreProjectController::class,
                    )
                        ->middleware('throttle:project-commands')
                        ->can('restore', 'project')
                        ->name('restore');
                });
        });
});

Route::get('/health', HealthController::class)
    ->name('health');

Route::get('/ready', ReadinessController::class)
    ->name('ready');

require __DIR__.'/settings.php';
