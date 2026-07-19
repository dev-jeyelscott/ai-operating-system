<?php

use App\Domain\Projects\ProjectSetupStep;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Health\HealthController;
use App\Http\Controllers\Health\ReadinessController;
use App\Http\Controllers\Organizations\OrganizationController;
use App\Http\Controllers\Organizations\OrganizationDashboardController;
use App\Http\Controllers\Organizations\SwitchCurrentOrganizationController;
use App\Http\Controllers\Projects\ArchiveProjectController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\ProjectSetupController;
use App\Http\Controllers\Projects\RestoreProjectController;
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

                    Route::controller(ProjectSetupController::class)
                        ->prefix('/{project}/setup')
                        ->name('setup.')
                        ->group(function (): void {
                            Route::get('/', 'start')
                                ->can('update', 'project')
                                ->name('start');

                            Route::get('/{step}', 'show')
                                ->whereIn(
                                    'step',
                                    ProjectSetupStep::values(),
                                )
                                ->can('update', 'project')
                                ->name('show');

                            Route::put('/{step}', 'update')
                                ->whereIn(
                                    'step',
                                    ProjectSetupStep::values(),
                                )
                                ->middleware('throttle:project-commands')
                                ->can('update', 'project')
                                ->name('update');
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
