<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Shared\Contracts\TransactionManager;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Executes application operations through Laravel database transactions.
 */
final class EloquentTransactionManager implements TransactionManager
{
    /**
     * Execute the supplied operation atomically.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(Closure $operation): mixed
    {
        return DB::transaction(
            $operation,
            attempts: 3,
        );
    }
}
