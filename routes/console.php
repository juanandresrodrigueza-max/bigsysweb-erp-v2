<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('alertas:generar')->everyFifteenMinutes();
Schedule::command('afip:reintentar')->everyFiveMinutes()->withoutOverlapping(); // contingencia: comprobantes pendientes de CAE
Schedule::command('suscripciones:revisar')->dailyAt('06:00');
Schedule::command('cotizaciones:actualizar')->twiceDaily(9, 15);
Schedule::command('abonos:emitir')->dailyAt('07:00');
Schedule::command('previsiones:procesar')->dailyAt('07:15');
Schedule::command('salud:revisar')->hourly(); // salud del servidor: avisa por mail si algo está crítico
Schedule::call(fn() => \Illuminate\Support\Facades\Cache::put(\App\Services\Monitoreo\SaludService::LATIDO_CRON, now()->toIso8601String(), 3600 * 24 * 30))->everyMinute()->name('salud:latido'); // previsiones recurrentes: registrar automáticas y avisar; recordatorios para facturar abonos
Schedule::command('cobranzas:recordar')->dailyAt('09:30');
Schedule::command('backups:diario')->dailyAt('03:00');
Schedule::command('analista:semanal')->weeklyOn(1, '08:00');
Schedule::command('avisos:dueno')->everyFifteenMinutes();
// Contabiliza lo que haya quedado sin asiento (red de seguridad; normalmente se genera al instante).
Schedule::call(function () {
    foreach (\App\Models\Business::where('is_active', true)->pluck('id') as $id) {
        app(\App\Services\Contabilidad\ContabilidadService::class)->sincronizar($id);
    }
})->hourly()->name('contabilidad:sincronizar');
