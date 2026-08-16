<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Without appendOutputTo the scheduler runs these as `> /dev/null 2>&1`, so the
// "N created, M skipped" summary each command prints is discarded every day.
// One shared file keeps both in a single timeline; each line names its command.
// ⚠️ This banks totals only. Which teams were skipped, and why, is still not
// recorded anywhere — that is the per-team verdict table under G11, and this
// does not replace it.
Schedule::command('pabd:auto-create')->daily()->at('06:00')->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/auto-create.log'));
Schedule::command('prbl:auto-create')->daily()->at('06:15')->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/auto-create.log'));

// The public demo hands every visitor the same accounts, so it has to be able
// to throw away whatever the last visitor did. 🔴 Guarded by config, never by
// APP_ENV — a stray `DEMO_RESET=true` is the only way this can reach real data.
// demo:reset rather than migrate:fresh directly: the database was only half of
// it. Uploaded files outlive the rows that point at them, so the command clears
// storage in the same pass. One entry, so the two halves cannot drift apart.
if (config('demo.reset')) {
    Schedule::command('demo:reset')->everyFifteenMinutes()
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/demo-reset.log'));
}
