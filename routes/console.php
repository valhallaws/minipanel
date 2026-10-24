<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('minipanel:purge-site-trash')->hourly()->withoutOverlapping();
Schedule::command('minipanel:run-scheduled-backups')->dailyAt('02:15')->withoutOverlapping(180)->runInBackground();
Schedule::command('minipanel:check-site-health')->everyTenMinutes()->withoutOverlapping(15)->runInBackground();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
