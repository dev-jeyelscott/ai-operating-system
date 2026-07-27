<?php

declare(strict_types=1);

namespace App\Infrastructure\Events;

use App\Application\Events\Contracts\DomainEventConsumer;
use App\Application\Events\Contracts\DomainEventConsumerRegistry;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Resolves domain-event consumers declared in application configuration.
 */
final readonly class ConfiguredDomainEventConsumerRegistry implements DomainEventConsumerRegistry
{
    /**
     * Create the configured consumer registry.
     *
     * @param  list<class-string>  $consumerClasses
     */
    public function __construct(
        private Container $container,
        private array $consumerClasses,
    ) {}

    /**
     * Resolve consumers subscribed to one event name.
     *
     * Consumer names must be globally unique because they form part of the
     * permanent duplicate-prevention key.
     *
     * @return list<DomainEventConsumer>
     */
    public function forEvent(string $eventName): array
    {
        $consumers = [];
        $consumerNames = [];

        foreach ($this->consumerClasses as $consumerClass) {
            $consumer = $this->container->make($consumerClass);

            if (! $consumer instanceof DomainEventConsumer) {
                throw new LogicException(sprintf(
                    'Configured event consumer [%s] must implement [%s].',
                    $consumerClass,
                    DomainEventConsumer::class,
                ));
            }

            $consumerName = trim($consumer->consumerName());

            if ($consumerName === '') {
                throw new LogicException(sprintf(
                    'Configured event consumer [%s] has an empty name.',
                    $consumerClass,
                ));
            }

            if (isset($consumerNames[$consumerName])) {
                throw new LogicException(sprintf(
                    'Duplicate domain-event consumer name [%s].',
                    $consumerName,
                ));
            }

            $consumerNames[$consumerName] = true;

            if (
                in_array(
                    $eventName,
                    $consumer->subscribedEventNames(),
                    true,
                )
            ) {
                $consumers[] = $consumer;
            }
        }

        return $consumers;
    }
}
