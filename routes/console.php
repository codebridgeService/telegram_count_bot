<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\SendStatsSummaryJob;


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');



// ថ្ងៃ — daily summary at 8:00 AM
Schedule::job(new SendStatsSummaryJob('day'))
    ->dailyAt('08:00')
    ->timezone('Asia/Phnom_Penh')
    ->withoutOverlapping()
    ->onOneServer();

// សប្តាហ៍ — weekly summary, Monday 8:00 AM
Schedule::job(new SendStatsSummaryJob('week'))
    ->weeklyOn(1, '08:00')
    ->timezone('Asia/Phnom_Penh')
    ->withoutOverlapping()
    ->onOneServer();

// ខែ — monthly summary, 1st of month 8:00 AM
Schedule::job(new SendStatsSummaryJob('month'))
    ->monthlyOn(1, '08:00')
    ->timezone('Asia/Phnom_Penh')
    ->withoutOverlapping()
    ->onOneServer();