<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Persist Horizon throughput, runtime, failure, and wait-time snapshots.
 *
 * Horizon snapshot storage is operational telemetry only and does not become
 * project workflow truth.
 */
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->withoutOverlapping(2)
    ->onOneServer();

/*
 * Database row claims provide correctness across concurrent dispatchers.
 * Scheduler locks reduce unnecessary duplicate scheduler invocations.
 */
Schedule::command('outbox:dispatch')
    ->everyTenSeconds()
    ->withoutOverlapping(2)
    ->onOneServer()
    ->runInBackground();

/*
 * Row locking and pending-state checks provide approval-expiry correctness.
 * The scheduler lock avoids unnecessary concurrent batch scans.
 */
Schedule::command('approvals:expire')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->onOneServer();

/*
 * Execution and attempt row locks provide lifecycle correctness. Scheduler
 * locks only reduce unnecessary duplicate recovery scans.
 */
Schedule::command('executions:recover')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->onOneServer();

/*
 * Remove expired non-authoritative provider transcript objects while keeping
 * immutable database, audit, and evidence provenance.
 */
Schedule::command('codex:prune-provider-transcripts')
    ->dailyAt('03:30')
    ->withoutOverlapping(10)
    ->onOneServer();
