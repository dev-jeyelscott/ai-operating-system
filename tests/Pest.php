<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Feature Tests
|--------------------------------------------------------------------------
|
| Normal feature tests use database transactions for fast isolation.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
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
*/

pest()->extend(TestCase::class)
    ->use(DatabaseMigrations::class)
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
