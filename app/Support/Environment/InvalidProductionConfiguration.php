<?php

declare(strict_types=1);

namespace App\Support\Environment;

use RuntimeException;

final class InvalidProductionConfiguration extends RuntimeException
{
    /**
     * @param  list<string>  $violations
     */
    public function __construct(private readonly array $violations)
    {
        parent::__construct(
            'Unsafe production configuration:'.PHP_EOL
            .'- '.implode(PHP_EOL.'- ', $violations),
        );
    }

    /**
     * Return the redacted, actionable configuration violations.
     *
     * @return list<string>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
