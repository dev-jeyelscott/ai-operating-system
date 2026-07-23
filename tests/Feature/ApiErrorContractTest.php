<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Shared\Exceptions\ConflictException;
use App\Application\Shared\Exceptions\RetryableOperationException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ApiErrorContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')
            ->prefix('api/test-errors')
            ->group(function (): void {
                Route::get('/conflict', function (): never {
                    throw new ConflictException('Version conflict.');
                });

                Route::get('/retryable', function (): never {
                    throw new RetryableOperationException(
                        'Dependency unavailable.',
                        45,
                    );
                });

                Route::get('/internal', function (): never {
                    throw new \RuntimeException(
                        'Sensitive internal message.',
                    );
                });
            });
    }

    /**
     * Verify conflict responses use a stable application error code.
     */
    public function test_conflict_error_contract(): void
    {
        $this->getJson('/api/test-errors/conflict')
            ->assertConflict()
            ->assertJsonPath('error.code', 'state_conflict')
            ->assertJsonPath('error.retryable', false);
    }

    /**
     * Verify retryable failures include retry metadata.
     */
    public function test_retryable_error_contract(): void
    {
        $this->getJson('/api/test-errors/retryable')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '45')
            ->assertJsonPath(
                'error.code',
                'temporarily_unavailable',
            )
            ->assertJsonPath('error.retryable', true);
    }

    /**
     * Verify internal exception messages are not exposed publicly.
     */
    public function test_internal_errors_do_not_leak_details(): void
    {
        $response = $this->getJson('/api/test-errors/internal');

        $response
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'internal_error')
            ->assertJsonMissing([
                'message' => 'Sensitive internal message.',
            ]);
    }
}
