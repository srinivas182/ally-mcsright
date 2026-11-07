<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Platform jobs (specs/core/01, specs/finance/01)
Schedule::command('tenants:lifecycle')->dailyAt('03:00')->onOneServer();
Schedule::command('ledger:reconcile')->dailyAt('04:00')->onOneServer();
