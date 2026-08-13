<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('pabd:auto-create')->daily()->at('06:00')->withoutOverlapping();
Schedule::command('prbl:auto-create')->daily()->at('06:15')->withoutOverlapping();
