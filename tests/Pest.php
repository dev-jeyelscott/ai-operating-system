<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Codex\FakeCodexServer;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Feature Tests
|--------------------------------------------------------------------------
|
| Normal feature tests use database transactions for fast isolation.
| Every framework-backed test also receives the deterministic Codex process
| boundary so normal test execution can never select the real Codex binary.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        FakeCodexServer::configureDefaults();
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Database Concurrency Tests
|--------------------------------------------------------------------------
|
| Concurrency tests require committed fixtures that are visible to separate
| PostgreSQL connections. DatabaseMigrations provides a clean schema without
| wrapping the test data inside the parent process transaction.
|
| The same fake Codex boundary is installed for the parent Laravel process.
| Child-process tests must continue passing the test environment explicitly
| when they bootstrap an independent PHP process.
|
*/

pest()->extend(TestCase::class)
    ->use(DatabaseMigrations::class)
    ->beforeEach(function (): void {
        FakeCodexServer::configureDefaults();
    })
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| Add project-specific expectations here.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| Add project-specific test helpers here.
|
*/

function something()
{
    // Reserved for shared Pest test helpers.
}
