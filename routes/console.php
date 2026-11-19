<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Polling fallback for lost webhooks (needs `php artisan schedule:work` locally / the scheduler on Laravel Cloud).
Schedule::command('generations:reconcile')->everyMinute()->withoutOverlapping();

// Shared hosting (Hostinger) has no long-running worker: when QUEUE_VIA_SCHEDULER=true,
// the cron-driven scheduler drains the queue every minute instead of `php artisan queue:work`.
if (env('QUEUE_VIA_SCHEDULER', false)) {
    Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=1')
        ->everyMinute()
        ->withoutOverlapping()
        ->runInBackground();
}
