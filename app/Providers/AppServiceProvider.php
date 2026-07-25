<?php

namespace App\Providers;

use App\Application\Documents\Contracts\DocumentAnalyzer;
use App\Application\Documents\Contracts\DocumentParser;
use App\Application\Documents\Contracts\MalwareScanner;
use App\Application\Shared\Contracts\TransactionManager;
use App\Application\Workflows\Contracts\WorkflowTransitionGuardEvaluator;
use App\Infrastructure\Documents\DeterministicDocumentAnalyzer;
use App\Infrastructure\Documents\DeterministicMalwareScanner;
use App\Infrastructure\Documents\PlainTextDocumentParser;
use App\Infrastructure\Persistence\EloquentTransactionManager;
use App\Infrastructure\Workflows\DenyAllWorkflowTransitionGuardEvaluator;
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
         * Guarded workflow transitions fail closed until an approved
         * deterministic evaluator replaces this default implementation.
         */
        $this->app->bind(
            WorkflowTransitionGuardEvaluator::class,
            DenyAllWorkflowTransitionGuardEvaluator::class,
        );
    }

    /**
     * Bootstrap application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
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
