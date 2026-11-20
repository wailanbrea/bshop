<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('bshop:reservations-expire --apply')->everyFiveMinutes()->withoutOverlapping(15)->appendOutputTo(storage_path('logs/reservation-expiry.log'));

Schedule::command('bshop:inventory-reconcile --json')->hourly()->withoutOverlapping(60)->appendOutputTo(storage_path('logs/inventory-reconciliation.log'));

Schedule::command('bshop:whatsapp-dispatch-scheduled')->everyMinute()->withoutOverlapping(10)->appendOutputTo(storage_path('logs/whatsapp-dispatch.log'));

Schedule::command('bshop:whatsapp-expiry-warnings')->hourly()->withoutOverlapping(30)->appendOutputTo(storage_path('logs/whatsapp-expiry.log'));

Schedule::command('bshop:whatsapp-owner-summary')->everyMinute()->withoutOverlapping(10)->appendOutputTo(storage_path('logs/whatsapp-owner-summary.log'));

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
