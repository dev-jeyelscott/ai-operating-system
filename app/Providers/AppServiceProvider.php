<?php

namespace App\Providers;

use App\Application\Shared\Contracts\TransactionManager;
use App\Infrastructure\Persistence\EloquentTransactionManager;
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
            TransactionManager::class,
            EloquentTransactionManager::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
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
     * Configure the password policy used by registration, reset, and update flows.
     *
     * A 15-character minimum supports secure passphrases without forcing arbitrary
     * uppercase, numeric, or symbol composition rules. Production additionally
     * checks the password against known compromised-password datasets.
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
