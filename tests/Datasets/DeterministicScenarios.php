<?php

declare(strict_types=1);

use App\Domain\Simulation\DeterministicScenario;

$scenarios = [];

foreach (DeterministicScenario::cases() as $scenario) {
    $scenarios[$scenario->value] = [$scenario];
}

dataset('deterministic scenarios', $scenarios);
