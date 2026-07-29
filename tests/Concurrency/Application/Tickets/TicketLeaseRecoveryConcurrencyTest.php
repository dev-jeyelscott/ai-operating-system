<?php

declare(strict_types=1);

namespace Tests\Concurrency\Application\Tickets;

use App\Application\Events\Data\StoredDomainEvent;
use App\Application\Tickets\Consumers\ReleaseLeaseForTerminalExecution;
use App\Application\Tickets\Data\TicketSelectionRequest;
use App\Application\Tickets\SelectNextTicketAndAcquireLease;
use App\Application\Tickets\TicketLeaseManager;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Tickets\TicketLeaseReleaseReason;
use App\Domain\Tickets\TicketStatus;
use App\Models\Execution;
use App\Models\TicketExecutionLease;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\TicketTestFixture;
use Tests\TestCase;

final class TicketLeaseRecoveryConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        try {
            if ($this->app !== null) {
                $this->truncateTablesForAllConnections();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_two_recovery_workers_release_one_expired_terminal_lease_once(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->fail('AIOS-093 concurrency verification requires pcntl.');
        }

        CarbonImmutable::setTestNow('2026-07-29 12:00:00');
        $fixture = TicketTestFixture::create(ticketAttributes: [
            'status' => TicketStatus::Ready,
            'desired_state' => TicketStatus::Ready,
            'status_changed_at' => now()->subMinute(),
            'ready_at' => now()->subMinute(),
        ]);
        $fixture['roadmap']->forceFill([
            'status' => 'approved',
            'approved_fingerprint' => $fixture['roadmap']->candidate_fingerprint,
            'approved_snapshot' => ['schema_version' => 1],
            'approved_at' => now(),
        ])->save();
        $execution = Execution::factory()->for($fixture['project'])->create([
            'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
            'capability' => 'development.simulation',
        ]);
        app(SelectNextTicketAndAcquireLease::class)->handle(
            new TicketSelectionRequest(
                organizationId: $fixture['project']->organization_id,
                projectId: $fixture['project']->id,
                executionId: $execution->id,
                owner: 'aios-093-concurrency-worker',
                leaseDurationSeconds: 30,
            ),
        );
        $execution->forceFill([
            'status' => ExecutionStatus::Completed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ])->save();
        CarbonImmutable::setTestNow('2026-07-29 12:01:00');
        DB::disconnect();
        $workers = [];

        for ($index = 0; $index < 2; $index++) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $this->assertIsArray($sockets);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);

            if ($pid === 0) {
                fclose($sockets[0]);
                DB::purge();
                fread($sockets[1], 1);

                try {
                    $released = app(TicketLeaseManager::class)->recoverExpired(limit: 1);
                    fwrite($sockets[1], json_encode(['released' => $released, 'error' => null], JSON_THROW_ON_ERROR));
                } catch (\Throwable $throwable) {
                    fwrite($sockets[1], json_encode(['released' => 0, 'error' => $throwable->getMessage()], JSON_THROW_ON_ERROR));
                }

                fclose($sockets[1]);
                exit(0);
            }

            fclose($sockets[1]);
            $workers[] = ['pid' => $pid, 'socket' => $sockets[0]];
        }

        foreach ($workers as $worker) {
            fwrite($worker['socket'], '1');
        }

        $results = [];

        foreach ($workers as $worker) {
            $payload = stream_get_contents($worker['socket']);
            fclose($worker['socket']);
            pcntl_waitpid($worker['pid'], $status);
            $results[] = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        }

        DB::reconnect();

        $this->assertSame([null, null], array_column($results, 'error'));
        $this->assertSame(1, array_sum(array_column($results, 'released')));
        $this->assertSame(0, TicketExecutionLease::query()->active()->count());
        $this->assertSame(3, DB::table('outbox_messages')->count());
        $this->assertSame(3, DB::table('audit_events')->count());
    }

    public function test_heartbeat_racing_terminal_release_cannot_revive_the_lease(): void
    {
        CarbonImmutable::setTestNow('2026-07-29 13:00:00');
        $fixture = $this->leaseFixture();
        $fixture['execution']->forceFill([
            'status' => ExecutionStatus::Completed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ])->save();
        CarbonImmutable::setTestNow('2026-07-29 13:00:10');

        $results = $this->runWorkers([
            'heartbeat' => fn (): TicketExecutionLease => app(TicketLeaseManager::class)->heartbeat(
                $fixture['project']->organization_id,
                $fixture['project']->id,
                $fixture['lease']->id,
                $fixture['execution']->id,
                'aios-102-concurrency-worker',
            ),
            'release' => fn (): TicketExecutionLease => app(TicketLeaseManager::class)->releaseForExecution(
                $fixture['project']->organization_id,
                $fixture['project']->id,
                $fixture['lease']->id,
                $fixture['execution']->id,
                'aios-102-concurrency-worker',
                TicketLeaseReleaseReason::Completion,
            ),
        ]);

        $this->assertNotNull($results['heartbeat']['error']);
        $this->assertNull($results['release']['error']);
        $lease = TicketExecutionLease::query()->findOrFail($fixture['lease']->id);
        $this->assertFalse($lease->isActive());
        $this->assertSame(TicketLeaseReleaseReason::Completion, $lease->release_reason);
        $this->assertSame(1, DB::table('outbox_messages')->where('event_name', 'ticket.lease_released')->count());
    }

    public function test_heartbeat_racing_recovery_cannot_revive_an_expired_terminal_lease(): void
    {
        CarbonImmutable::setTestNow('2026-07-29 14:00:00');
        $fixture = $this->leaseFixture(leaseDurationSeconds: 30);
        $fixture['execution']->forceFill([
            'status' => ExecutionStatus::Failed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ])->save();
        CarbonImmutable::setTestNow('2026-07-29 14:01:00');

        $results = $this->runWorkers([
            'heartbeat' => fn (): TicketExecutionLease => app(TicketLeaseManager::class)->heartbeat(
                $fixture['project']->organization_id,
                $fixture['project']->id,
                $fixture['lease']->id,
                $fixture['execution']->id,
                'aios-102-concurrency-worker',
            ),
            'recovery' => fn (): int => app(TicketLeaseManager::class)->recoverExpired(limit: 1),
        ]);

        $this->assertNotNull($results['heartbeat']['error']);
        $this->assertNull($results['recovery']['error']);
        $this->assertContains($results['recovery']['result'], [0, 1]);
        $followUpReleased = app(TicketLeaseManager::class)->recoverExpired(limit: 1);
        $this->assertSame(1, $results['recovery']['result'] + $followUpReleased);
        $lease = TicketExecutionLease::query()->findOrFail($fixture['lease']->id);
        $this->assertFalse($lease->isActive());
        $this->assertSame(TicketLeaseReleaseReason::TerminalFailure, $lease->release_reason);
        $this->assertSame(1, DB::table('outbox_messages')->where('event_name', 'ticket.lease_released')->count());
    }

    public function test_concurrent_terminal_event_replay_releases_one_lease_once(): void
    {
        CarbonImmutable::setTestNow('2026-07-29 15:00:00');
        $fixture = $this->leaseFixture();
        $fixture['execution']->forceFill([
            'status' => ExecutionStatus::Completed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ])->save();
        $event = new StoredDomainEvent(
            eventId: '01KYPHASE7CONCURRENTREPLAY0',
            eventName: 'execution.attempt.completed',
            organizationId: $fixture['project']->organization_id,
            projectId: $fixture['project']->id,
            schemaVersion: 1,
            envelope: ['payload' => ['execution_id' => $fixture['execution']->id]],
        );

        $results = $this->runWorkers([
            'first' => fn (): null => app(ReleaseLeaseForTerminalExecution::class)->handle($event),
            'replay' => fn (): null => app(ReleaseLeaseForTerminalExecution::class)->handle($event),
        ]);

        $this->assertNull($results['first']['error']);
        $this->assertNull($results['replay']['error']);
        $this->assertFalse(TicketExecutionLease::query()->findOrFail($fixture['lease']->id)->isActive());
        $this->assertSame(1, DB::table('outbox_messages')->where('event_name', 'ticket.lease_released')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'ticket.lease_released')->count());
    }

    /** @return array<string, mixed> */
    private function leaseFixture(int $leaseDurationSeconds = 300): array
    {
        $fixture = TicketTestFixture::create(ticketAttributes: [
            'status' => TicketStatus::Ready,
            'desired_state' => TicketStatus::Ready,
            'status_changed_at' => now()->subMinute(),
            'ready_at' => now()->subMinute(),
        ]);
        $fixture['roadmap']->forceFill([
            'status' => 'approved',
            'approved_fingerprint' => $fixture['roadmap']->candidate_fingerprint,
            'approved_snapshot' => ['schema_version' => 1],
            'approved_at' => now(),
        ])->save();
        $execution = Execution::factory()->for($fixture['project'])->create([
            'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
            'capability' => 'development.simulation',
        ]);
        $selection = app(SelectNextTicketAndAcquireLease::class)->handle(new TicketSelectionRequest(
            organizationId: $fixture['project']->organization_id,
            projectId: $fixture['project']->id,
            executionId: $execution->id,
            owner: 'aios-102-concurrency-worker',
            leaseDurationSeconds: $leaseDurationSeconds,
        ));

        return [
            ...$fixture,
            'execution' => $execution,
            'lease' => TicketExecutionLease::query()->findOrFail($selection->leaseId),
        ];
    }

    /**
     * @param  array<string, callable(): mixed>  $operations
     * @return array<string, array{result:mixed,error:string|null}>
     */
    private function runWorkers(array $operations): array
    {
        if (! function_exists('pcntl_fork')) {
            $this->fail('AIOS-102 concurrency verification requires pcntl.');
        }

        DB::disconnect();
        $workers = [];

        foreach ($operations as $name => $operation) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $this->assertIsArray($sockets);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);

            if ($pid === 0) {
                fclose($sockets[0]);
                DB::purge();
                fread($sockets[1], 1);

                try {
                    $result = $operation();
                    $payload = ['result' => $result, 'error' => null];
                } catch (\Throwable $throwable) {
                    $payload = ['result' => null, 'error' => $throwable->getMessage()];
                }

                fwrite($sockets[1], json_encode($payload, JSON_THROW_ON_ERROR));
                fclose($sockets[1]);
                exit(0);
            }

            fclose($sockets[1]);
            $workers[$name] = ['pid' => $pid, 'socket' => $sockets[0]];
        }

        foreach ($workers as $worker) {
            fwrite($worker['socket'], '1');
        }

        $results = [];

        foreach ($workers as $name => $worker) {
            $payload = stream_get_contents($worker['socket']);
            fclose($worker['socket']);
            pcntl_waitpid($worker['pid'], $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $results[$name] = ['result' => $decoded['result'], 'error' => $decoded['error']];
        }

        DB::reconnect();

        return $results;
    }
}
