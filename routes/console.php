<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('alertas:generar')->everyFifteenMinutes();
Schedule::command('suscripciones:revisar')->dailyAt('06:00');
