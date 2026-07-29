<?php

declare(strict_types=1);

use BackedEnum;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Foundation\Application;
use JsonSerializable;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Convert an application result into JSON-safe worker output.
 */
function normalizeWorkerValue(mixed $value): mixed
{
    if ($value instanceof Arrayable) {
        return normalizeWorkerValue($value->toArray());
    }

    if ($value instanceof JsonSerializable) {
        return normalizeWorkerValue($value->jsonSerialize());
    }

    if ($value instanceof BackedEnum) {
        return $value->value;
    }

    if ($value instanceof UnitEnum) {
        return $value->name;
    }

    if ($value instanceof Stringable) {
        return (string) $value;
    }

    if (is_array($value)) {
        return array_map(
            static fn (mixed $item): mixed => normalizeWorkerValue($item),
            $value,
        );
    }

    if (is_object($value)) {
        return normalizeWorkerValue(get_object_vars($value));
    }

    return $value;
}

/**
 * Write a structured worker failure and exit with a non-zero status.
 */
function failWorker(Throwable $exception): never
{
    fwrite(
        STDERR,
        json_encode(
            [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ],
            JSON_THROW_ON_ERROR,
        ).PHP_EOL,
    );

    exit(1);
}

$rootPath = dirname(__DIR__, 3);

require $rootPath.'/vendor/autoload.php';

/** @var Application $app */
$app = require $rootPath.'/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

try {
    $encodedPayload = $argv[1] ?? null;

    if (! is_string($encodedPayload) || $encodedPayload === '') {
        throw new InvalidArgumentException('The worker payload is required.');
    }

    $decodedPayload = base64_decode($encodedPayload, true);

    if ($decodedPayload === false) {
        throw new InvalidArgumentException('The worker payload is not valid base64.');
    }

    /** @var array{
     *     action: class-string,
     *     method?: string,
     *     arguments?: array<string, mixed>
     * } $payload
     */
    $payload = json_decode(
        $decodedPayload,
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $connectionName = (string) config('database.default');
    $databaseName = (string) config(
        "database.connections.{$connectionName}.database",
    );

    if (! app()->environment('testing')) {
        throw new RuntimeException(
            'Concurrency workers may only run in the testing environment.',
        );
    }

    if (! str_starts_with($databaseName, 'testing')) {
        throw new RuntimeException(
            "Refusing to run against non-testing database [{$databaseName}].",
        );
    }

    $actionClass = $payload['action'] ?? null;
    $method = $payload['method'] ?? 'handle';
    $arguments = $payload['arguments'] ?? [];

    if (! is_string($actionClass) || ! class_exists($actionClass)) {
        throw new InvalidArgumentException(
            'The worker action must be an existing class.',
        );
    }

    if (! is_string($method) || $method === '') {
        throw new InvalidArgumentException(
            'The worker method must be a non-empty string.',
        );
    }

    if (! is_array($arguments)) {
        throw new InvalidArgumentException(
            'The worker arguments must be an associative array.',
        );
    }

    $action = $app->make($actionClass);

    $result = $app->call(
        [$action, $method],
        $arguments,
    );

    fwrite(
        STDOUT,
        json_encode(
            normalizeWorkerValue($result),
            JSON_THROW_ON_ERROR,
        ).PHP_EOL,
    );

    exit(0);
} catch (Throwable $exception) {
    failWorker($exception);
}
