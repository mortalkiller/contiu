<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('finance:generate-recurring')->dailyAt('06:15');
Schedule::command('finance:send-reminders')->dailyAt('06:30');
Schedule::command('finance:send-tax-obligations')->dailyAt('08:00');
Schedule::command('invoicing:sync')->hourly()->withoutOverlapping();

// Backups (spatie/laravel-backup): clean old archives, back up, then check the newest one is healthy.
Schedule::command('backup:clean')->dailyAt('01:00');
Schedule::command('backup:run')->dailyAt('01:30');
Schedule::command('backup:monitor')->dailyAt('02:00');
