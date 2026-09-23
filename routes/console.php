<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('alertas:generar')->everyFifteenMinutes();
Schedule::command('suscripciones:revisar')->dailyAt('06:00');
// Contabiliza lo que haya quedado sin asiento (red de seguridad; normalmente se genera al instante).
Schedule::call(function () {
    foreach (\App\Models\Business::where('is_active', true)->pluck('id') as $id) {
        app(\App\Services\Contabilidad\ContabilidadService::class)->sincronizar($id);
    }
})->hourly()->name('contabilidad:sincronizar');
