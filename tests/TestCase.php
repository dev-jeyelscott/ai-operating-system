<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Inertia\Inertia;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Prepare the shared Laravel test environment.
     *
     * Backend feature tests validate Laravel and Inertia responses without
     * requiring compiled Vite assets or an external Inertia SSR process.
     * Frontend builds and SSR runtime behavior remain separate CI concerns.
     */
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Prevent backend feature tests from resolving compiled Vite assets.
         */
        $this->withoutVite();

        /*
         * Prevent Inertia page rendering from making an HTTP request to the
         * Vite or production SSR server during backend feature tests.
         */
        Inertia::disableSsr();
    }

    /**
     * Skip the current test when the required Fortify feature is disabled.
     */
    protected function skipUnlessFortifyHas(
        string $feature,
        ?string $message = null,
    ): void {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped(
                $message ?? "Fortify feature [{$feature}] is not enabled.",
            );
        }
    }
}
