<?php

namespace App\Providers;

use App\Application\Development\DevelopmentProviderRegistry;
use App\Application\Development\DevelopmentResultValidator;
use App\Application\Documents\Contracts\DocumentAnalyzer;
use App\Application\Documents\Contracts\DocumentParser;
use App\Application\Documents\Contracts\MalwareScanner;
use App\Application\Planning\ExecutionProviderRegistry;
use App\Application\Planning\PlanningResultValidator;
use App\Application\QualityAssurance\QaAssessmentValidator;
use App\Application\QualityAssurance\QualityAssuranceProviderRegistry;
use App\Application\Shared\Contracts\TransactionManager;
use App\Application\Workflows\Contracts\WorkflowTransitionGuardEvaluator;
use App\Infrastructure\AgentProviders\SimulationPlanningProvider;
use App\Infrastructure\Development\SimulationDevelopmentProvider;
use App\Infrastructure\Documents\DeterministicDocumentAnalyzer;
use App\Infrastructure\Documents\DeterministicMalwareScanner;
use App\Infrastructure\Documents\PlainTextDocumentParser;
use App\Infrastructure\Persistence\EloquentTransactionManager;
use App\Infrastructure\QualityAssurance\SimulationQualityAssuranceProvider;
use App\Infrastructure\Workflows\RoadmapWorkflowTransitionGuardEvaluator;
use App\Models\Artifact;
use App\Models\Evidence;
use App\Models\ExecutionAttempt;
use App\Models\NotificationEvent;
use App\Observers\SensitivePersistenceObserver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application-wide infrastructure abstractions.
     */
    public function register(): void
    {
        $this->app->bind(
            DocumentAnalyzer::class,
            DeterministicDocumentAnalyzer::class,
        );

        $this->app->bind(
            MalwareScanner::class,
            DeterministicMalwareScanner::class,
        );

        $this->app->bind(
            DocumentParser::class,
            PlainTextDocumentParser::class,
        );

        $this->app->bind(
            TransactionManager::class,
            EloquentTransactionManager::class,
        );

        /*
         * Roadmap guards are explicit and every unknown guard fails closed.
         */
        $this->app->bind(
            WorkflowTransitionGuardEvaluator::class,
            RoadmapWorkflowTransitionGuardEvaluator::class,
        );

        $this->app->singleton(
            SimulationPlanningProvider::class,
        );

        $this->app->singleton(
            ExecutionProviderRegistry::class,
            fn ($app): ExecutionProviderRegistry => new ExecutionProviderRegistry(
                providers: [
                    $app->make(
                        SimulationPlanningProvider::class,
                    ),
                ],
                validator: $app->make(
                    PlanningResultValidator::class,
                ),
            ),
        );

        $this->app->singleton(
            SimulationDevelopmentProvider::class,
        );

        $this->app->singleton(
            DevelopmentProviderRegistry::class,
            fn ($app): DevelopmentProviderRegistry => new DevelopmentProviderRegistry(
                providers: [
                    $app->make(
                        SimulationDevelopmentProvider::class,
                    ),
                ],
                validator: $app->make(
                    DevelopmentResultValidator::class,
                ),
            ),
        );

        $this->app->singleton(
            SimulationQualityAssuranceProvider::class,
        );

        $this->app->singleton(
            QualityAssuranceProviderRegistry::class,
            fn ($app): QualityAssuranceProviderRegistry => new QualityAssuranceProviderRegistry(
                providers: [
                    $app->make(
                        SimulationQualityAssuranceProvider::class,
                    ),
                ],
                validator: $app->make(
                    QaAssessmentValidator::class,
                ),
            ),
        );
    }

    /**
     * Bootstrap application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureSensitivePersistenceObservers();
    }

    /**
     * Configure secure application defaults.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        $this->configurePasswordPolicy();
    }

    /**
     * Register fail-closed redaction boundaries for sensitive records.
     */
    private function configureSensitivePersistenceObservers(): void
    {
        Artifact::observe(
            SensitivePersistenceObserver::class,
        );

        Evidence::observe(
            SensitivePersistenceObserver::class,
        );

        NotificationEvent::observe(
            SensitivePersistenceObserver::class,
        );

        ExecutionAttempt::observe(
            SensitivePersistenceObserver::class,
        );
    }

    /**
     * Configure the password policy used by authentication flows.
     *
     * A 15-character minimum supports secure passphrases without forcing
     * arbitrary uppercase, numeric, or symbol composition rules. Production
     * additionally checks against known compromised-password datasets.
     */
    private function configurePasswordPolicy(): void
    {
        Password::defaults(function (): Password {
            $password = Password::min(15);

            return app()->isProduction()
                ? $password->uncompromised()
                : $password;
        });
    }
}
