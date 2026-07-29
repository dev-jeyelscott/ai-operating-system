<?php

declare(strict_types=1);

namespace Tests\Concurrency\Application\Tickets;

use App\Application\Tickets\Data\TicketSelectionRequest;
use App\Application\Tickets\SelectNextTicketAndAcquireLease;
use App\Application\Tickets\TicketLeaseManager;
use App\Domain\Executions\ExecutionStatus;
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
}
