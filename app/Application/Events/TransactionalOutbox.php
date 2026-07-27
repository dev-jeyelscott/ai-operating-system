<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Application\Events\Contracts\DomainEventOutbox;
use App\Application\Shared\Contracts\TransactionManager;
use Closure;

/**
 * Executes authoritative state mutations and outbox appends atomically.
 */
final readonly class TransactionalOutbox
{
    /**
     * Inject the application transaction boundary and outbox contract.
     */
    public function __construct(
        private TransactionManager $transactions,
        private DomainEventOutbox $outbox,
    ) {}

    /**
     * Execute one business operation inside a database transaction.
     *
     * The operation receives the outbox contract so it can append all domain
     * events before the transaction commits.
     *
     * @template TResult
     *
     * @param  Closure(DomainEventOutbox): TResult  $operation
     * @return TResult
     */
    public function run(Closure $operation): mixed
    {
        return $this->transactions->run(
            function () use ($operation): mixed {
                return $operation($this->outbox);
            },
        );
    }
}
