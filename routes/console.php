<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('panel:auth-prune')->daily()->withoutOverlapping();

Schedule::command('media:prune')->hourly()->withoutOverlapping();

Schedule::command('blog:publish-due')->everyTenSeconds()->runInBackground()->withoutOverlapping();

Schedule::command('blog:prune')->hourly()->withoutOverlapping();

Schedule::command('news:publish-due')->everyTenSeconds()->runInBackground()->withoutOverlapping();

Schedule::command('news:prune')->hourly()->withoutOverlapping();

Schedule::command('exchanges:prune')->hourly()->withoutOverlapping();

Schedule::command('platform-profiles:prune broker')->hourly()->withoutOverlapping();

Schedule::command('platform-profiles:prune stock-brokers')->hourly()->withoutOverlapping();

Schedule::command('platform-profiles:prune gold-platforms')->hourly()->withoutOverlapping();

Schedule::command('platform-profiles:prune prop-firms')->hourly()->withoutOverlapping();

Schedule::command('bonuses:prune')->hourly()->withoutOverlapping();

Schedule::command('tutorials:prune')->hourly()->withoutOverlapping();
Schedule::command('comments:prune')->hourly()->withoutOverlapping();

Schedule::command('presentation:deliver')->everyTenSeconds()->runInBackground()->withoutOverlapping();
Schedule::command('analytics:prune')->daily()->withoutOverlapping();
