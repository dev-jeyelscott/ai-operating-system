<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Application\Events\Contracts\DomainEventConsumer;
use App\Application\Events\Contracts\DomainEventConsumptionStore;
use App\Application\Events\Data\StoredDomainEvent;
use App\Application\Shared\Contracts\TransactionManager;

/**
 * Executes one consumer once per event using a durable database receipt.
 */
final readonly class DeduplicatedDomainEventConsumer
{
    /**
     * Create the consumer execution service.
     */
    public function __construct(
        private TransactionManager $transactions,
        private DomainEventConsumptionStore $consumptions,
    ) {}

    /**
     * Apply one consumer transactionally.
     *
     * The receipt is inserted before the handler runs but remains uncommitted.
     * A thrown exception rolls back both the receipt and database side effects,
     * allowing the queue retry to execute safely.
     */
    public function handle(
        StoredDomainEvent $event,
        DomainEventConsumer $consumer,
    ): bool {
        return $this->transactions->run(
            function () use ($event, $consumer): bool {
                if (
                    ! $this->consumptions->claim(
                        event: $event,
                        consumerName: $consumer->consumerName(),
                    )
                ) {
                    return false;
                }

                $consumer->handle($event);

                return true;
            },
        );
    }
}
