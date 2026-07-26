<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Database row claims provide correctness across concurrent dispatchers.
 * Scheduler locks reduce unnecessary duplicate scheduler invocations.
 */
Schedule::command('outbox:dispatch')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->onOneServer();

/*
 * Row locking and pending-state checks provide approval-expiry correctness.
 * The scheduler lock avoids unnecessary concurrent batch scans.
 */
Schedule::command('approvals:expire')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->onOneServer();
