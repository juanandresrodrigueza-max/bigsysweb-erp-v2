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

    // Fase 94.3: el latido del cron vive en un archivo de storage, no sólo en la caché: `optimize:clear` (que corre en cada
    // actualización) borraba la caché y el semáforo mostraba «Nunca corrió» durante un minuto. La caché queda como respaldo.
    // En las pruebas se fija el espacio libre: el disco de la máquina donde corren no es el del servidor (Fase 124).
    public static ?\Closure $espacioDisco = null;

    public static function archivoLatido(): string { return storage_path('framework/salud-latido'); }

    public static function latir(): void
    {
        $ahora = now()->toIso8601String();
        try { @file_put_contents(self::archivoLatido(), $ahora); } catch (\Throwable) {}
        try { Cache::put(self::LATIDO_CRON, $ahora, 3600 * 24 * 30); } catch (\Throwable) {}
    }

    public static function latido(): ?string
    {
        $f = self::archivoLatido();
        $archivo = is_readable($f) ? trim((string) @file_get_contents($f)) : '';
        $cache = Cache::get(self::LATIDO_CRON);
        if ($archivo && $cache) return strcmp($archivo, $cache) >= 0 ? $archivo : $cache; // el más reciente de los dos
        return $archivo ?: $cache;
    }

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
        $latido = self::latido();
        $min = $latido ? (int) abs(now()->diffInMinutes(\Carbon\Carbon::parse($latido))) : null;
        $c[] = $this->item('cron', 'Tareas programadas (cron)', $min === null ? 'aviso' : ($min > 30 ? 'critico' : ($min > 5 ? 'aviso' : 'ok')), $min === null ? 'Nunca corrió: falta el cron `php artisan schedule:run` cada minuto.' : "Último latido hace {$min} min");
        // Disco
        try { [$libre, $total] = self::$espacioDisco ? (self::$espacioDisco)() : [disk_free_space(storage_path()), disk_total_space(storage_path())]; $pct = $total ? round($libre / $total * 100) : 0; $c[] = $this->item('disco', 'Disco', $pct < 5 ? 'critico' : ($pct < 15 ? 'aviso' : 'ok'), round($libre / 1024 / 1024 / 1024, 1) . " GB libres ({$pct}%)"); }
        catch (\Throwable $e) { $c[] = $this->item('disco', 'Disco', 'aviso', $e->getMessage()); }
        // Copias de seguridad
        $ultimo = Backup::withoutGlobalScopes()->max('created_at');
        $horas = $ultimo ? (int) abs(now()->diffInHours(\Carbon\Carbon::parse($ultimo))) : null;
        $c[] = $this->item('backups', 'Copias de seguridad', $horas === null ? 'aviso' : ($horas > 72 ? 'critico' : ($horas > 30 ? 'aviso' : 'ok')), $horas === null ? 'Todavía no hay copias.' : "Última hace {$horas} h");
        // Fase 78: ¿las copias sirven? ¿están fuera del servidor?
        try {
            $est = collect(\App\Services\Migracion\BackupService::estadoPorEmpresa())->where('auto', true)->filter(fn($e) => $e['ultima']);
            $falladas = $est->where('verificada', false); $sinVerificar = $est->whereNull('verificada'); $ensayoMal = $est->filter(fn($e) => $e['ensayo'] && ! $e['ensayo']['ok']); $sinEnsayo = $est->filter(fn($e) => (! $e['ensayo'] && $e['hace_horas'] > 24 * 7) || ($e['ensayo'] && $e['ensayo']['hace_dias'] > 45));
            $c[] = $this->item('copias_verificadas', 'Copias verificadas', $falladas->count() || $ensayoMal->count() ? 'critico' : ($sinVerificar->count() || $sinEnsayo->count() ? 'aviso' : ($est->count() ? 'ok' : 'aviso')),
                ! $est->count() ? 'Ninguna empresa con copia todavía.' : trim(($falladas->count() ? "{$falladas->count()} copia(s) con problemas: " . $falladas->pluck('empresa')->implode(', ') . '. ' : '') . ($ensayoMal->count() ? "Ensayo de restauración fallido en: " . $ensayoMal->pluck('empresa')->implode(', ') . '. ' : '') . ($sinVerificar->count() ? "{$sinVerificar->count()} sin verificar. " : '') . ($sinEnsayo->count() ? "{$sinEnsayo->count()} sin ensayo de restauración en 45 días. " : '') . (! $falladas->count() && ! $ensayoMal->count() && ! $sinVerificar->count() && ! $sinEnsayo->count() ? "Las {$est->count()} copias se abren, cuadran y se restauran." : '')));
            $remoto = \App\Services\Migracion\BackupService::remotoConfigurado(); $sinRemoto = $remoto ? $est->where('remoto', false) : collect();
            $c[] = $this->item('copias_remotas', 'Copias fuera del servidor', ! $remoto ? 'aviso' : ($sinRemoto->count() ? 'critico' : 'ok'), ! $remoto ? 'Sin disco externo (BACKUP_DISK): las copias viven en el mismo disco del servidor.' : ($sinRemoto->count() ? "{$sinRemoto->count()} copia(s) no se subieron: " . $sinRemoto->pluck('empresa')->implode(', ') : 'Todas las copias están también en ' . config('services.backups.disk') . '.'));
        } catch (\Throwable $e) { $c[] = $this->item('copias_verificadas', 'Copias verificadas', 'aviso', $e->getMessage()); }
        // Fase 123: la copia completa de la base (para recuperar el ERP entero si se pierde el servidor).
        try {
            $cc = \App\Services\Migracion\CopiaCompletaService::estado(); $h = $cc['ultima']['hace_horas'] ?? null;
            [$est, $det] = match (true) {
                ! $cc['clave'] => ['aviso', 'Falta la clave de las copias completas: generala en Panel → Sistema.'],
                ! $cc['disco'] => ['aviso', 'Sin disco externo (BACKUP_DISK): la copia completa queda sólo en el servidor.'],
                $cc['fallo'] !== null => ['critico', "La última copia completa falló ({$cc['fallo']['fecha']}): " . mb_substr((string) $cc['fallo']['error'], 0, 160)],
                $h === null => ['aviso', 'Todavía no se hizo ninguna copia completa (corre a las 03:20).'],
                $h > 30 => ['critico', "La última copia completa es de hace {$h} h."],
                ! $cc['ultima']['remoto'] => ['critico', 'La última copia completa no se pudo subir fuera del servidor.'],
                default => ['ok', "Última hace {$h} h ({$cc['ultima']['mb']} MB, {$cc['ultima']['tablas']} tablas), cifrada y en {$cc['disco']}. Afuera hay {$cc['fuera']}."],
            };
            $c[] = $this->item('copia_completa', 'Copia completa de la base', $est, $det);
        } catch (\Throwable $e) { $c[] = $this->item('copia_completa', 'Copia completa de la base', 'aviso', $e->getMessage()); }
        // ARCA pendientes
        $pendArca = Comprobante::withoutGlobalScopes()->where('estado', 'emitido')->where('afip_estado', 'pendiente')->count();
        $viejos = Comprobante::withoutGlobalScopes()->where('estado', 'emitido')->where('afip_estado', 'pendiente')->where('emitido_en', '<', now()->subHours(2))->count();
        $c[] = $this->item('arca', 'Comprobantes pendientes de CAE', $viejos > 0 ? 'critico' : ($pendArca > 0 ? 'aviso' : 'ok'), $pendArca ? "{$pendArca} pendientes" . ($viejos ? ", {$viejos} desde hace más de 2 h" : '') : 'Ninguno');
        // Fase 126: lo emitido con CAEA (ARCA caído) que todavía no se informó. Rechazado, o a 2 días del tope, es crítico.
        try {
            $sinInf = Comprobante::withoutGlobalScopes()->where('estado', 'emitido')->where('cae_tipo', 'CAEA')->whereNull('caea_informado_en')->get(['id', 'cae', 'afip_respuesta']);
            $topes = \App\Models\ArcaCaea::whereIn('caea', $sinInf->pluck('cae')->unique())->pluck('tope_informar', 'caea');
            $urgentes = $sinInf->filter(fn($x) => ! empty($x->afip_respuesta['error_informe']) || (($t = $topes[$x->cae] ?? null) && today()->gte($t->copy()->subDays(\App\Services\Afip\CaeaService::AVISO_TOPE))))->count();
            $c[] = $this->item('caea', 'Comprobantes con CAEA sin informar', $urgentes ? 'critico' : ($sinInf->count() ? 'aviso' : 'ok'), $sinInf->count() ? "{$sinInf->count()} sin informar a ARCA" . ($urgentes ? ", {$urgentes} rechazados o cerca del tope" : ' (se informan solos cuando ARCA responde)') : 'Ninguno');
        } catch (\Throwable $e) { $c[] = $this->item('caea', 'Comprobantes con CAEA sin informar', 'aviso', $e->getMessage()); }
        // Fase 121.1: el certificado de BigSys con el que se factura en nombre de las empresas. Vencido o por vencer, nadie factura.
        try {
            $ae = \App\Services\Afip\ArcaPlataforma::estado(); $usan = (int) $ae['empresas'] + (int) \App\Models\BusinessLocation::withoutGlobalScopes()->where('arca_bigsys', true)->count();
            $info = ($cert = \App\Models\SistemaConfig::get('arca_cert')) ? \App\Services\Afip\ArcaPlataforma::leerCertificado($cert) : null;
            $dias = $info ? (int) floor(now()->diffInDays($info['vence'], false)) : null;
            if (! $ae['lista']) $c[] = $this->item('arca_certificado', 'Certificado de BigSys para ARCA', $usan ? 'critico' : 'ok', $usan ? "Falta el certificado y hay {$usan} empresa(s) o sucursal(es) que facturan por BigSys: emiten simulado." : 'Sin cargar (ninguna empresa factura a través de BigSys).');
            else $c[] = $this->item('arca_certificado', 'Certificado de BigSys para ARCA', $dias < 15 ? 'critico' : ($dias < 30 || (! $ae['produccion'] && $usan) ? 'aviso' : 'ok'),
                ($dias < 0 ? 'VENCIDO el ' . $ae['vence'] . ': nadie puede facturar por BigSys. Renovalo en ARCA y cargalo en Panel → Sistema.' : "Alias {$ae['alias']} · vence el {$ae['vence']} (en {$dias} días)" . ($dias < 30 ? ': renovalo en ARCA y cargalo en Panel → Sistema.' : ''))
                . ' · ' . ($ae['produccion'] ? 'producción' : 'homologación: los CAE no valen fiscalmente') . " · {$usan} empresa(s)/sucursal(es) lo usan.");
        } catch (\Throwable $e) { $c[] = $this->item('arca_certificado', 'Certificado de BigSys para ARCA', 'aviso', $e->getMessage()); }
        // Fase 121.2: lo que promete la privacidad: a los 90 días de la baja, los datos de la empresa se eliminan (lo hace el superadmin).
        try {
            $vencidas = \App\Models\Business::onlyTrashed()->where('deleted_at', '<', now()->subDays(90))->orderBy('deleted_at')->get(['id', 'name', 'deleted_at']);
            $c[] = $this->item('bajas_vencidas', 'Empresas dadas de baja hace más de 90 días', $vencidas->count() ? 'aviso' : 'ok', $vencidas->count()
                ? $vencidas->count() . ' esperan que se eliminen sus datos, como dice la política de privacidad: ' . $vencidas->take(5)->map(fn($b) => "{$b->name} (baja el {$b->deleted_at->format('d/m/Y')})")->implode(', ') . ($vencidas->count() > 5 ? '…' : '') . '. Panel → Empresas → ficha → «Eliminar definitivamente».'
                : 'Ninguna pendiente de eliminar.');
        } catch (\Throwable $e) { $c[] = $this->item('bajas_vencidas', 'Empresas dadas de baja hace más de 90 días', 'aviso', $e->getMessage()); }
        // Cotización
        $cot = Cotizacion::withoutGlobalScopes()->whereNull('business_id')->where('tipo', 'oficial')->max('fecha');
        $dias = $cot ? (int) abs(today()->diffInDays(\Carbon\Carbon::parse($cot))) : null;
        $intento = Cache::get(\App\Services\Fondos\CotizacionService::ULTIMO_INTENTO);
        $intentoTxt = $intento ? ' · último intento ' . \Carbon\Carbon::parse($intento['cuando'])->format('d/m H:i') . ': ' . ($intento['ok'] ? 'OK (' . $intento['detalle'] . ')' : 'falló (' . $intento['detalle'] . ')') : ' · todavía no corrió cotizaciones:actualizar desde el último reinicio (va a las 9 y a las 15)';
        // Un intento OK en las últimas 36 h vale como "al día" aunque la fila tenga fecha anterior (fines de semana, feriados, valores que no cambian).
        $intentoReciente = $intento && $intento['ok'] && \Carbon\Carbon::parse($intento['cuando'])->gt(now()->subHours(36));
        $c[] = $this->item('cotizacion', 'Cotización del dólar', $dias === null ? 'aviso' : ($dias > 3 && ! $intentoReciente ? 'aviso' : 'ok'), ($dias === null ? 'Sin cotización cargada.' : "Última del " . \Carbon\Carbon::parse($cot)->format('d/m/Y')) . $intentoTxt);
        // Correo e IA
        $c[] = $this->item('correo', 'Correo saliente', config('mail.default') === 'smtp' ? 'ok' : 'aviso', config('mail.default') === 'smtp' ? 'SMTP configurado' : 'Sin SMTP: los avisos por mail no salen (' . config('mail.default') . ')');
        $c[] = $this->item('ia', 'Inteligencia artificial', config('services.anthropic.api_key') ? 'ok' : 'aviso', config('services.anthropic.api_key') ? 'Clave cargada' : 'Sin clave: el asistente trabaja en modo básico');
        // Fase 77: páginas lentas en la ronda nocturna (crítico si una página viene lenta dos noches seguidas o da error).
        try {
            $m = app(MedicionService::class)->ultimaRonda();
            $c[] = $this->item('paginas', 'Velocidad de las páginas', $m['ronda'] === null ? 'aviso' : (($m['lentas_dos_noches'] > 0 || ($m['con_error'] ?? 0) > 0) ? 'critico' : ($m['lentas'] > 0 ? 'aviso' : 'ok')),
                $m['ronda'] === null ? 'Todavía no corrió la medición nocturna (erp:medir --nocturna).' : ($m['lentas'] ? "{$m['lentas']} página(s) por encima de {$m['umbral']} ms" . ($m['lentas_dos_noches'] ? ", {$m['lentas_dos_noches']} dos noches seguidas" : '') . (($m['con_error'] ?? 0) ? ", {$m['con_error']} con error" : '') . " (ronda {$m['ronda']})" : "Todas por debajo de {$m['umbral']} ms (ronda {$m['ronda']})"));
        } catch (\Throwable $e) { $c[] = $this->item('paginas', 'Velocidad de las páginas', 'aviso', $e->getMessage()); }
        // Errores
        $err24 = (int) ErrorSistema::where('ultima_vez', '>=', now()->subDay())->whereNull('resuelto_en')->sum('veces');
        $c[] = $this->item('errores', 'Errores en las últimas 24 h', $err24 >= 20 ? 'critico' : ($err24 > 0 ? 'aviso' : 'ok'), $err24 ? "{$err24} errores (ver abajo)" : 'Ninguno');
        $destinos = Destinatarios::monitoreo();
        $c[] = $this->item('sentry', 'Monitoreo externo (Sentry)', ErrorReporter::dsn() || $destinos ? 'ok' : 'aviso', ErrorReporter::dsn() ? 'DSN cargado: los errores se envían' : ($destinos ? 'Sin Sentry, pero cada error nuevo avisa por mail a ' . implode(', ', $destinos) . ' y queda en este panel (opcional)' : 'Sin DSN ni mail de monitoreo: los errores solo quedan en este panel'));

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
        $email = Destinatarios::monitoreo();
        if ($nuevos && $email) {
            try { \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\AvisoSalud($nuevos, $r)); } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('Salud: no se pudo avisar: ' . $e->getMessage()); }
        }
        return ['estado' => $r['estado'], 'criticos' => count($criticos), 'avisados' => $nuevos && $email ? count($nuevos) : 0];
    }
}
