<?php

declare(strict_types=1);

use App\Application\Shared\Commands\Command;
use App\Application\Shared\Commands\CommandBus;
use App\Application\Shared\Commands\CommandResult;
use App\Application\Shared\Idempotency\Contracts\IdempotencyKeyService;
use App\Application\Shared\Idempotency\Contracts\IdempotentCommand;
use App\Domain\Idempotency\IdempotencyKeyStatus;
use App\Infrastructure\Bus\IdempotentCommandBus;
use App\Models\IdempotencyKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set([
        'idempotency.cache_store' => 'array',
        'idempotency.lock_wait_seconds' => 1,
        'idempotency.processing_ttl_seconds' => 60,
        'idempotency.retention_seconds' => 3600,
    ]);

    app()->forgetInstance(IdempotencyKeyService::class);
    app()->forgetInstance(CommandBus::class);
});

it('registers the idempotent command bus decorator', function (): void {
    expect(app(CommandBus::class))
        ->toBeInstanceOf(IdempotentCommandBus::class);
});

it('returns the original terminal result for a duplicate command', function (): void {
    $service = app(IdempotencyKeyService::class);

    $command = new Aios057IdempotentTestCommand(
        scope: 'organization:org-1:project:project-1:start-project',
        key: 'request-001',
        payload: [
            'project_id' => 'project-1',
            'snapshot_id' => 'snapshot-1',
        ],
    );

    $calls = 0;

    $first = $service->execute(
        $command,
        function () use (&$calls): CommandResult {
            $calls++;

            return CommandResult::succeeded([
                'execution_id' => 'execution-1',
            ]);
        },
    );

    $second = $service->execute(
        $command,
        function () use (&$calls): CommandResult {
            $calls++;

            return CommandResult::succeeded([
                'execution_id' => 'execution-2',
            ]);
        },
    );

    expect($calls)
        ->toBe(1)
        ->and($second->toArray())
        ->toBe($first->toArray())
        ->and($second->toArray()['data']['execution_id'])
        ->toBe('execution-1')
        ->and(IdempotencyKey::query()->count())
        ->toBe(1);

    $record = IdempotencyKey::query()->sole();

    expect($record->status)
        ->toBe(IdempotencyKeyStatus::Completed)
        ->and($record->result_payload)
        ->toBe($first->toArray())
        ->and($record->lock_owner)
        ->toBeNull()
        ->and($record->completed_at)
        ->not->toBeNull()
        ->and($record->expires_at)
        ->not->toBeNull();
});

it('does not persist the raw key or plaintext result payload', function (): void {
    $service = app(IdempotencyKeyService::class);
    $rawKey = 'customer-supplied-secret-looking-key';

    $command = new Aios057IdempotentTestCommand(
        scope: 'organization:org-1:project:project-1:start-project',
        key: $rawKey,
        payload: [
            'project_id' => 'project-1',
        ],
    );

    $service->execute(
        $command,
        fn (): CommandResult => CommandResult::succeeded([
            'execution_id' => 'sensitive-execution-reference',
        ]),
    );

    $stored = DB::table('idempotency_keys')->sole();
    $rawPayload = $stored->result_payload;

    expect($stored->key_hash)
        ->toBe(hash('sha256', $rawKey))
        ->and(json_encode($stored, JSON_THROW_ON_ERROR))
        ->not->toContain($rawKey)
        ->and($rawPayload)
        ->toBeString()
        ->not->toContain('sensitive-execution-reference');
});

it('rejects reuse of a key with different command input', function (): void {
    $service = app(IdempotencyKeyService::class);

    $original = new Aios057IdempotentTestCommand(
        scope: 'organization:org-1:project:project-1:start-project',
        key: 'request-002',
        payload: [
            'snapshot_id' => 'snapshot-1',
        ],
    );

    $changed = new Aios057IdempotentTestCommand(
        scope: 'organization:org-1:project:project-1:start-project',
        key: 'request-002',
        payload: [
            'snapshot_id' => 'snapshot-2',
        ],
    );

    $service->execute(
        $original,
        fn (): CommandResult => CommandResult::succeeded([
            'execution_id' => 'execution-1',
        ]),
    );

    $changedOperationWasCalled = false;

    $result = $service->execute(
        $changed,
        function () use (&$changedOperationWasCalled): CommandResult {
            $changedOperationWasCalled = true;

            return CommandResult::succeeded();
        },
    );

    expect($changedOperationWasCalled)
        ->toBeFalse()
        ->and($result->toArray()['status'])
        ->toBe('conflict')
        ->and($result->toArray()['error']['code'])
        ->toBe('state_conflict')
        ->and($result->toArray()['error']['details']['reason'])
        ->toBe('idempotency_key_reused');
});

it('isolates identical keys by explicit tenant operation scope', function (): void {
    $service = app(IdempotencyKeyService::class);
    $calls = 0;

    $firstProject = new Aios057IdempotentTestCommand(
        scope: 'organization:org-1:project:project-1:start-project',
        key: 'shared-client-key',
        payload: [
            'project_id' => 'project-1',
        ],
    );

    $secondProject = new Aios057IdempotentTestCommand(
        scope: 'organization:org-1:project:project-2:start-project',
        key: 'shared-client-key',
        payload: [
            'project_id' => 'project-2',
        ],
    );

    $service->execute(
        $firstProject,
        function () use (&$calls): CommandResult {
            $calls++;

            return CommandResult::succeeded();
        },
    );

    $service->execute(
        $secondProject,
        function () use (&$calls): CommandResult {
            $calls++;

            return CommandResult::succeeded();
        },
    );

    expect($calls)
        ->toBe(2)
        ->and(IdempotencyKey::query()->count())
        ->toBe(2);
});

it('does not permanently cache retryable failures', function (): void {
    $service = app(IdempotencyKeyService::class);

    $command = new Aios057IdempotentTestCommand(
        scope: 'organization:org-1:project:project-1:start-project',
        key: 'request-003',
        payload: [
            'project_id' => 'project-1',
        ],
    );

    $calls = 0;

    $first = $service->execute(
        $command,
        function () use (&$calls): CommandResult {
            $calls++;

            return CommandResult::retryableFailure(
                message: 'Temporary dependency failure.',
                retryAfterSeconds: 10,
            );
        },
    );

    expect($first->isRetryable())
        ->toBeTrue()
        ->and(IdempotencyKey::query()->count())
        ->toBe(0);

    $second = $service->execute(
        $command,
        function () use (&$calls): CommandResult {
            $calls++;

            return CommandResult::succeeded([
                'execution_id' => 'execution-after-retry',
            ]);
        },
    );

    expect($calls)
        ->toBe(2)
        ->and($second->isSuccessful())
        ->toBeTrue()
        ->and(IdempotencyKey::query()->count())
        ->toBe(1);
});

it('releases a claim when command execution throws', function (): void {
    $service = app(IdempotencyKeyService::class);

    $command = new Aios057IdempotentTestCommand(
        scope: 'organization:org-1:project:project-1:start-project',
        key: 'request-004',
        payload: [
            'project_id' => 'project-1',
        ],
    );

    expect(
        fn (): CommandResult => $service->execute(
            $command,
            function (): CommandResult {
                throw new RuntimeException('Unexpected command failure.');
            },
        ),
    )->toThrow(
        RuntimeException::class,
        'Unexpected command failure.',
    );

    expect(IdempotencyKey::query()->count())->toBe(0);

    $result = $service->execute(
        $command,
        fn (): CommandResult => CommandResult::succeeded([
            'execution_id' => 'execution-after-exception',
        ]),
    );

    expect($result->isSuccessful())
        ->toBeTrue()
        ->and(IdempotencyKey::query()->count())
        ->toBe(1);
});

it('returns a retryable result while an active database claim exists', function (): void {
    $service = app(IdempotencyKeyService::class);

    $payload = [
        'project_id' => 'project-1',
    ];

    $command = new Aios057IdempotentTestCommand(
        scope: 'organization:org-1:project:project-1:start-project',
        key: 'request-005',
        payload: $payload,
    );

    $encodedPayload = json_encode(
        $payload,
        JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION,
    );

    IdempotencyKey::query()->create([
        'scope' => $command->idempotencyScope(),
        'key_hash' => hash(
            'sha256',
            $command->idempotencyKey(),
        ),
        'command_class' => $command::class,
        'request_fingerprint' => hash(
            'sha256',
            $encodedPayload,
        ),
        'status' => IdempotencyKeyStatus::Processing,
        'result_status' => null,
        'result_payload' => null,
        'lock_owner' => (string) Str::uuid(),
        'lock_expires_at' => CarbonImmutable::now()->addMinute(),
        'completed_at' => null,
        'expires_at' => null,
    ]);

    $operationWasCalled = false;

    $result = $service->execute(
        $command,
        function () use (&$operationWasCalled): CommandResult {
            $operationWasCalled = true;

            return CommandResult::succeeded();
        },
    );

    expect($operationWasCalled)
        ->toBeFalse()
        ->and($result->isRetryable())
        ->toBeTrue()
        ->and($result->toArray()['error']['details']['reason'])
        ->toBe('idempotency_command_in_progress');
});

it('reclaims an expired processing claim', function (): void {
    $service = app(IdempotencyKeyService::class);

    $payload = [
        'project_id' => 'project-1',
    ];

    $command = new Aios057IdempotentTestCommand(
        scope: 'organization:org-1:project:project-1:start-project',
        key: 'request-006',
        payload: $payload,
    );

    $encodedPayload = json_encode(
        $payload,
        JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION,
    );

    IdempotencyKey::query()->create([
        'scope' => $command->idempotencyScope(),
        'key_hash' => hash(
            'sha256',
            $command->idempotencyKey(),
        ),
        'command_class' => $command::class,
        'request_fingerprint' => hash(
            'sha256',
            $encodedPayload,
        ),
        'status' => IdempotencyKeyStatus::Processing,
        'result_status' => null,
        'result_payload' => null,
        'lock_owner' => (string) Str::uuid(),
        'lock_expires_at' => CarbonImmutable::now()->subMinute(),
        'completed_at' => null,
        'expires_at' => null,
    ]);

    $operationWasCalled = false;

    $result = $service->execute(
        $command,
        function () use (&$operationWasCalled): CommandResult {
            $operationWasCalled = true;

            return CommandResult::succeeded([
                'execution_id' => 'recovered-execution',
            ]);
        },
    );

    expect($operationWasCalled)
        ->toBeTrue()
        ->and($result->isSuccessful())
        ->toBeTrue()
        ->and(IdempotencyKey::query()->sole()->status)
        ->toBe(IdempotencyKeyStatus::Completed);
});

it('applies idempotency only to commands that opt in', function (): void {
    $service = app(IdempotencyKeyService::class);
    $inner = new Aios057RecordingCommandBus;

    $bus = new IdempotentCommandBus(
        inner: $inner,
        idempotencyKeys: $service,
    );

    $idempotent = new Aios057IdempotentTestCommand(
        scope: 'organization:org-1:project:project-1:start-project',
        key: 'request-007',
        payload: [
            'project_id' => 'project-1',
        ],
    );

    $first = $bus->dispatch($idempotent);
    $second = $bus->dispatch($idempotent);

    expect($inner->calls)
        ->toBe(1)
        ->and($second->toArray())
        ->toBe($first->toArray());

    $normalCommand = new Aios057NormalTestCommand;

    $bus->dispatch($normalCommand);
    $bus->dispatch($normalCommand);

    expect($inner->calls)->toBe(3);
});

/**
 * Test command that supplies a stable idempotency contract.
 */
final readonly class Aios057IdempotentTestCommand implements IdempotentCommand
{
    /**
     * Create the deterministic command fixture.
     *
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private string $scope,
        private string $key,
        private array $payload,
    ) {}

    /**
     * Return the test idempotency key.
     */
    public function idempotencyKey(): string
    {
        return $this->key;
    }

    /**
     * Return the test tenant and operation scope.
     */
    public function idempotencyScope(): string
    {
        return $this->scope;
    }

    /**
     * Return the deterministic test payload.
     *
     * @return array<string, mixed>
     */
    public function idempotencyPayload(): array
    {
        return $this->payload;
    }
}

/**
 * Test command that intentionally does not request idempotency.
 */
final readonly class Aios057NormalTestCommand implements Command {}

/**
 * Records the number of underlying command-bus executions.
 */
final class Aios057RecordingCommandBus implements CommandBus
{
    public int $calls = 0;

    /**
     * Return a unique result for each actual inner-bus dispatch.
     */
    public function dispatch(Command $command): CommandResult
    {
        $this->calls++;

        return CommandResult::succeeded([
            'call' => $this->calls,
            'command' => $command::class,
        ]);
    }
}
