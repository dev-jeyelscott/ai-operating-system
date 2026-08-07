<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTruncation;

/**
 * Provides database isolation for tests that execute work in child processes.
 *
 * Transaction-based test isolation cannot be used because child processes
 * require committed database rows. Tables are therefore truncated before and
 * after each test while keeping the migrated schema intact.
 */
abstract class ProcessDatabaseTestCase extends TestCase
{
    use DatabaseTruncation;

    /**
     * Remove committed test records before the application is destroyed.
     *
     * DatabaseTruncation normally prepares the database before each test.
     * Explicit post-test truncation prevents committed rows from contaminating
     * subsequent feature tests that use transaction-based RefreshDatabase.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->app)) {
                $this->truncateTablesForAllConnections();
            }
        } finally {
            parent::tearDown();
        }
    }
}
