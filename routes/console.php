<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('alertas:generar')->everyFifteenMinutes();
