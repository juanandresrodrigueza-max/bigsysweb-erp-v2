<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('alertas:generar')->everyFifteenMinutes();
Schedule::command('afip:reintentar')->everyFiveMinutes()->withoutOverlapping(); // contingencia: comprobantes pendientes de CAE
Schedule::command('agentes:correr')->dailyAt('05:45')->withoutOverlapping(); // Fase 107: Bigcito trabaja de noche y deja propuestas
Schedule::command('whatsapp:plantillas')->twiceDaily(8, 14); // Fase 120.1: ¿Meta ya aprobó las plantillas de cada empresa?
Schedule::command('bigcito:rutinas')->everyFiveMinutes()->withoutOverlapping(); // Fase 119.4: lo que cada usuario le dejó encargado a Bigcito
Schedule::command('casilla:revisar')->everyFiveMinutes()->withoutOverlapping(); // Fase 120.3: las facturas que llegaron por mail
Schedule::command('encargos:recordar')->hourlyAt(7)->withoutOverlapping(); // Fase 120.6: lo que quedó vencido se recuerda una vez por día
Schedule::command('suscripciones:revisar')->dailyAt('06:00');
Schedule::command('aprobaciones:avisar')->hourly(); // Fase 106: altas pendientes por bloquearse / bloqueadas
Schedule::command('cotizaciones:actualizar')->twiceDaily(9, 15);
Schedule::command('abonos:emitir')->dailyAt('07:00');
Schedule::command('previsiones:procesar')->dailyAt('07:15');
Schedule::command('mercadopago:movimientos')->dailyAt('05:20')->withoutOverlapping(); // Fase 99: los movimientos de Mercado Pago, solos
Schedule::command('arca:vencimiento')->dailyAt('09:12'); // Fase 123.3: el certificado de BigSys para ARCA, 30/7/1 días antes
Schedule::command('salud:revisar')->hourly(); // salud del servidor: avisa por mail si algo está crítico
Schedule::call(fn() => \App\Services\Monitoreo\SaludService::latir())->everyMinute()->name('salud:latido'); // previsiones recurrentes: registrar automáticas y avisar; recordatorios para facturar abonos
Schedule::command('cobranzas:recordar')->dailyAt('09:30');
Schedule::command('backups:diario')->dailyAt('03:00');
Schedule::command('backups:completa')->dailyAt('03:20')->withoutOverlapping(180); // Fase 123: la base entera, cifrada y afuera
Schedule::command('backups:probar')->dailyAt('03:40');                 // Fase 78: cada copia se abre y se comprueba
Schedule::command('backups:probar --ensayo')->weeklyOn(0, '05:10');    // y los domingos se ensaya la restauración
Schedule::command('erp:medir --nocturna')->dailyAt('04:40'); // Fase 77: las páginas clave de las empresas grandes, medidas cada noche
Schedule::command('analista:semanal')->weeklyOn(1, '08:00');
Schedule::command('sistema:resumen-semanal')->weeklyOn(1, '07:50'); // Fase 76: la semana del sistema con uso real, al dueño de BigSys
Schedule::command('avisos:dueno')->everyFifteenMinutes();
Schedule::command('crm:salud')->everyFifteenMinutes()->withoutOverlapping();            // Fase 68: ¿el CRM responde? alerta si se corta
Schedule::command('crm:salud --reconciliar')->dailyAt('06:30');
Schedule::command('crm:avisos')->dailyAt('07:40');                                        // Fase 73: lo crítico al vendedor en el CRM; tareas vencidas del CRM a la campana                            // y una vez por día vuelve a mandar lo de 48 h
Schedule::command('canales:sincronizar')->everyFiveMinutes()->withoutOverlapping();            // precio y stock hacia las tiendas
Schedule::command('canales:sincronizar --pedidos')->everyTenMinutes()->withoutOverlapping();  // y las ventas que no llegaron por aviso
// Contabiliza lo que haya quedado sin asiento (red de seguridad; normalmente se genera al instante).
Schedule::call(function () {
    foreach (\App\Models\Business::where('is_active', true)->pluck('id') as $id) {
        app(\App\Services\Contabilidad\ContabilidadService::class)->sincronizar($id);
    }
})->hourly()->name('contabilidad:sincronizar');
