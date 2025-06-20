<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('giveaway:draw-winners')->everyMinute();
Schedule::command('app:validate-checkout')->everyMinute();
