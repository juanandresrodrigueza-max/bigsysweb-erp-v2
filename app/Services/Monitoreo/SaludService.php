<?php

namespace App\Services\Monitoreo;

use App\Models\Backup;
use App\Models\Comprobante;
use App\Models\Cotizacion;
use App\Models\ErrorSistema;
use App\Models\SistemaConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Salud del servidor: cada control devuelve ok / aviso / critico con un detalle en criollo.
class SaludService
{
    public const LATIDO_CRON = 'salud_cron_latido';

    public function verificar(): array
    {
        $c = [];
        // Base de datos
        try { $t = microtime(true); DB::select('select 1'); $ms = (int) ((microtime(true) - $t) * 1000); $c[] = $this->item('base', 'Base de datos', $ms > 500 ? 'aviso' : 'ok', config('database.default') . " · responde en {$ms} ms"); }
        catch (\Throwable $e) { $c[] = $this->item('base', 'Base de datos', 'critico', 'No responde: ' . $e->getMessage()); }
        // Caché
        try { Cache::put('salud_ping', 1, 60); $c[] = $this->item('cache', 'Caché', Cache::get('salud_ping') === 1 ? 'ok' : 'aviso', config('cache.default')); }
        catch (\Throwable $e) { $c[] = $this->item('cache', 'Caché', 'critico', $e->getMessage()); }
        // Cola de trabajos
        try {
            $pend = (int) DB::table('jobs')->count(); $fallidos = (int) DB::table('failed_jobs')->count();
            $masViejo = DB::table('jobs')->min('created_at'); $espera = $masViejo ? (int) abs(now()->diffInMinutes(\Carbon\Carbon::createFromTimestamp((int) $masViejo))) : 0;
            $estado = $fallidos >= 10 || $espera > 60 ? 'critico' : ($fallidos > 0 || $espera > 15 ? 'aviso' : 'ok');
            $c[] = $this->item('cola', 'Cola de trabajos (worker)', $estado, "{$pend} en espera" . ($espera ? " (el más viejo hace {$espera} min: ¿está corriendo el worker?)" : '') . " · {$fallidos} fallidos");
        } catch (\Throwable $e) { $c[] = $this->item('cola', 'Cola de trabajos', 'aviso', $e->getMessage()); }
        // Cron
        $latido = Cache::get(self::LATIDO_CRON);
        $min = $latido ? (int) abs(now()->diffInMinutes(\Carbon\Carbon::parse($latido))) : null;
        $c[] = $this->item('cron', 'Tareas programadas (cron)', $min === null ? 'aviso' : ($min > 30 ? 'critico' : ($min > 5 ? 'aviso' : 'ok')), $min === null ? 'Nunca corrió: falta el cron `php artisan schedule:run` cada minuto.' : "Último latido hace {$min} min");
        // Disco
        try { $libre = disk_free_space(storage_path()); $total = disk_total_space(storage_path()); $pct = $total ? round($libre / $total * 100) : 0; $c[] = $this->item('disco', 'Disco', $pct < 5 ? 'critico' : ($pct < 15 ? 'aviso' : 'ok'), round($libre / 1024 / 1024 / 1024, 1) . " GB libres ({$pct}%)"); }
        catch (\Throwable $e) { $c[] = $this->item('disco', 'Disco', 'aviso', $e->getMessage()); }
        // Copias de seguridad
        $ultimo = Backup::withoutGlobalScopes()->max('created_at');
        $horas = $ultimo ? (int) abs(now()->diffInHours(\Carbon\Carbon::parse($ultimo))) : null;
        $c[] = $this->item('backups', 'Copias de seguridad', $horas === null ? 'aviso' : ($horas > 72 ? 'critico' : ($horas > 30 ? 'aviso' : 'ok')), $horas === null ? 'Todavía no hay copias.' : "Última hace {$horas} h");
        // ARCA pendientes
        $pendArca = Comprobante::withoutGlobalScopes()->where('estado', 'emitido')->where('afip_estado', 'pendiente')->count();
        $viejos = Comprobante::withoutGlobalScopes()->where('estado', 'emitido')->where('afip_estado', 'pendiente')->where('emitido_en', '<', now()->subHours(2))->count();
        $c[] = $this->item('arca', 'Comprobantes pendientes de CAE', $viejos > 0 ? 'critico' : ($pendArca > 0 ? 'aviso' : 'ok'), $pendArca ? "{$pendArca} pendientes" . ($viejos ? ", {$viejos} desde hace más de 2 h" : '') : 'Ninguno');
        // Cotización
        $cot = Cotizacion::withoutGlobalScopes()->whereNull('business_id')->where('tipo', 'oficial')->max('fecha');
        $dias = $cot ? (int) abs(today()->diffInDays(\Carbon\Carbon::parse($cot))) : null;
        $c[] = $this->item('cotizacion', 'Cotización del dólar', $dias === null ? 'aviso' : ($dias > 3 ? 'aviso' : 'ok'), $dias === null ? 'Sin cotización cargada.' : "Última del " . \Carbon\Carbon::parse($cot)->format('d/m/Y'));
        // Correo e IA
        $c[] = $this->item('correo', 'Correo saliente', config('mail.default') === 'smtp' ? 'ok' : 'aviso', config('mail.default') === 'smtp' ? 'SMTP configurado' : 'Sin SMTP: los avisos por mail no salen (' . config('mail.default') . ')');
        $c[] = $this->item('ia', 'Inteligencia artificial', config('services.anthropic.api_key') ? 'ok' : 'aviso', config('services.anthropic.api_key') ? 'Clave cargada' : 'Sin clave: el asistente trabaja en modo básico');
        // Errores
        $err24 = (int) ErrorSistema::where('ultima_vez', '>=', now()->subDay())->whereNull('resuelto_en')->sum('veces');
        $c[] = $this->item('errores', 'Errores en las últimas 24 h', $err24 >= 20 ? 'critico' : ($err24 > 0 ? 'aviso' : 'ok'), $err24 ? "{$err24} errores (ver abajo)" : 'Ninguno');
        $c[] = $this->item('sentry', 'Monitoreo externo (Sentry)', ErrorReporter::dsn() ? 'ok' : 'aviso', ErrorReporter::dsn() ? 'DSN cargado: los errores se envían' : 'Sin DSN: los errores solo quedan en este panel');

        $estado = collect($c)->contains('estado', 'critico') ? 'critico' : (collect($c)->contains('estado', 'aviso') ? 'aviso' : 'ok');
        return ['estado' => $estado, 'controles' => $c, 'hora' => now()->toIso8601String(), 'version' => ['php' => PHP_VERSION, 'laravel' => app()->version()]];
    }

    private function item(string $key, string $nombre, string $estado, string $detalle): array { return compact('key', 'nombre', 'estado', 'detalle'); }

    // Revisión programada: si hay algo crítico avisa por mail al superadmin (una vez cada 6 horas por control).
    public function revisarYAvisar(): array
    {
        $r = $this->verificar();
        $criticos = array_values(array_filter($r['controles'], fn($c) => $c['estado'] === 'critico'));
        $nuevos = array_values(array_filter($criticos, fn($c) => Cache::add('salud_avisado_' . $c['key'], 1, now()->addHours(6))));
        $email = SistemaConfig::get('monitoreo_email') ?: SistemaConfig::get('soporte_email');
        if ($nuevos && $email) {
            try { \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\AvisoSalud($nuevos, $r)); } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('Salud: no se pudo avisar: ' . $e->getMessage()); }
        }
        return ['estado' => $r['estado'], 'criticos' => count($criticos), 'avisados' => $nuevos && $email ? count($nuevos) : 0];
    }
}
