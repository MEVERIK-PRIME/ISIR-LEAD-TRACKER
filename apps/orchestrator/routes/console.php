<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('isir:dispatch-sync')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('creditors:enrich --limit=100')->hourly()->withoutOverlapping();
Schedule::command('leads:sync-sheet --direction=both')->everyTenMinutes()->withoutOverlapping();
