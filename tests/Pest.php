<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Codex\FakeCodexServer;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Feature Tests
|--------------------------------------------------------------------------
|
| Normal feature tests use database transactions for fast isolation.
| Every framework-backed feature test also receives the deterministic Codex
| process boundary so normal test execution cannot select the real binary.
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
| Concurrency tests require committed database records because separate PHP
| processes and PostgreSQL connections must be able to observe the fixtures.
|
| DatabaseTruncation provides that isolation without wrapping fixtures in the
| parent process transaction and, critically, without rolling migrations back
| after every test. This keeps the shared testing schema stable for the rest
| of the quality suite.
|
| The deterministic fake Codex boundary is also installed for the parent
| Laravel process. Child processes remain responsible for receiving their
| explicit testing environment when they are launched.
|
*/

pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
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
