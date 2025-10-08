<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('giveaway:draw-winners')->everyMinute();
// TEMPORARILY DISABLED FOR WEBHOOK TESTING - relying on webhooks instead of polling
// Schedule::command('app:validate-checkout')->everyMinute();
