<?php

declare(strict_types=1);

namespace Tests\Concurrency\Application\Development;

use App\Application\Development\DevelopmentArtifactRecorder;
use App\Models\Artifact;
use App\Models\Evidence;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Project;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DevelopmentArtifactRecorderConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        try {
            if ($this->app !== null) {
                $this->truncateTablesForAllConnections();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_concurrent_artifact_and_evidence_writers_return_one_winning_row(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->fail('AIOS-099 concurrency verification requires pcntl.');
        }
        $project = Project::factory()->create();
        $execution = Execution::factory()->for($project)->create(['capability' => 'development.simulation']);
        $attempt = ExecutionAttempt::factory()->for($execution)->completed()->create([
            'simulation_mode' => 'simulated', 'simulation_seed' => '99',
        ]);
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
                    $artifact = app(DevelopmentArtifactRecorder::class)->record(
                        $execution, $attempt, 'synthetic_pull_request', 'pr-99',
                        'simulation://projects/99/pull-requests/pr-99', ['target' => 'develop'], ['Synthetic PR.'],
                    );
                    fwrite($sockets[1], json_encode(['id' => $artifact->id, 'error' => null], JSON_THROW_ON_ERROR));
                } catch (\Throwable $throwable) {
                    fwrite($sockets[1], json_encode(['id' => null, 'error' => $throwable->getMessage()], JSON_THROW_ON_ERROR));
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
        $this->assertCount(1, array_unique(array_column($results, 'id')));
        $this->assertSame(1, Artifact::query()->count());
        $this->assertSame(1, Evidence::query()->count());
    }
}
