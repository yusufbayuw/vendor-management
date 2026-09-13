<?php

use App\Services\Notifications\OperationalReminderService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('vendor:send-reminders', function (OperationalReminderService $reminders): void {
    $result = $reminders->send();

    foreach ($result as $type => $count) {
        $this->line(sprintf('%s: %d notifikasi', $type, $count));
    }
})->purpose('Kirim reminder operasional procurement, delivery, dokumen supplier, dan invoice');

Schedule::command('vendor:send-reminders')
    ->dailyAt('07:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();
