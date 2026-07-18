<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Prepare the shared Laravel test environment.
     *
     * Backend feature tests validate Laravel and Inertia responses rather than
     * compiled frontend assets. The production Vite build remains a separate
     * CI quality gate through the frontend quality command.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
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
