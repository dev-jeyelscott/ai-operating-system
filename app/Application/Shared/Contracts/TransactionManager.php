<?php

declare(strict_types=1);

namespace App\Application\Shared\Contracts;

use Closure;

/**
 * Provides an application-layer transaction boundary without coupling
 * application services to Laravel's database facade.
 */
interface TransactionManager
{
    /**
     * Execute an operation inside one database transaction.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(Closure $operation): mixed;
}
