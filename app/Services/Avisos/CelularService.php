<?php

namespace App\Services\Avisos;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Comprobante;
use App\Models\MejoraInteres;
use App\Models\Novedad;
use App\Models\PushSuscripcion;
use App\Models\SistemaConfig;
use App\Models\User;
use App\Services\Ventas\AvisosDuenoService;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

// Fase 40.5: avisos al celular. Web push por dispositivo, la campanita de novedades de BigSys (posponer 7 o 30 días),
// el parte de la mañana a la hora que elige el dueño y la página de mejoras con lo que no tiene contratado.
class CelularService
{
    public const QUE_HACE = [
        'agenda' => 'Turnos y recordatorios por WhatsApp para tus clientes.', 'obras' => 'Presupuestos, certificados y costos por obra.',
        'produccion' => 'Recetas, órdenes de producción y costo real de lo que fabricás.', 'sueldos' => 'Liquidación, recibos firmados desde el celular y F.931.',
        'contable' => 'Asientos solos, balance, libros y cierre de ejercicio.', 'gastronomia' => 'Mesas, comandas a cocina, delivery y propinas.',
        'retail' => 'Punto de venta rápido con lector, balanza y sin internet.', 'servicios' => 'Órdenes de taller, fotos, aprobación del cliente y técnicos.',
        'hoteleria' => 'Reservas, check-in, consumos a la habitación y canales.', 'minimarket' => 'Caja ágil con promociones y control de vencimientos.',
    ];
    public const MANANA = ['activo' => false, 'hora' => '08:00', 'push' => true, 'whatsapp' => false, 'ultimo' => null];

    // Las claves VAPID se crean una vez para todo el sistema y quedan guardadas.
    public function vapid(): array
    {
        $v = json_decode((string) SistemaConfig::get('vapid'), true); // va cifrada: es la clave privada del sistema
        if (! is_array($v) || empty($v['publicKey'])) { $v = VAPID::createVapidKeys(); SistemaConfig::set('vapid', json_encode($v)); }
        return $v;
    }

    public function suscribir(User $u, array $s, ?string $dispositivo): PushSuscripcion
    {
        return PushSuscripcion::updateOrCreate(['hash' => hash('sha256', $s['endpoint'])], ['user_id' => $u->id, 'endpoint' => $s['endpoint'], 'p256dh' => $s['keys']['p256dh'], 'auth' => $s['keys']['auth'], 'dispositivo' => $dispositivo ? mb_substr($dispositivo, 0, 120) : null]);
    }

    public function desuscribir(User $u, ?string $endpoint): int
    {
        return PushSuscripcion::where('user_id', $u->id)->when($endpoint, fn($q) => $q->where('hash', hash('sha256', $endpoint)))->delete();
    }

    // Manda a todos los dispositivos de la persona. Los que el navegador dio de baja se borran solos.
    public function enviar(User $u, string $titulo, string $texto, string $url = '/dashboard'): int
    {
        $subs = PushSuscripcion::where('user_id', $u->id)->get();
        if ($subs->isEmpty()) return 0;
        $v = $this->vapid();
        $wp = new WebPush(['VAPID' => ['subject' => config('app.url') ?: 'mailto:soporte@bigsys.com.ar', 'publicKey' => $v['publicKey'], 'privateKey' => $v['privateKey']]], [], 20);
        $payload = json_encode(['titulo' => $titulo, 'texto' => mb_substr($texto, 0, 300), 'url' => $url], JSON_UNESCAPED_UNICODE);
        foreach ($subs as $s) $wp->queueNotification(Subscription::create(['endpoint' => $s->endpoint, 'publicKey' => $s->p256dh, 'authToken' => $s->auth]), $payload);
        $ok = 0;
        foreach ($wp->flush() as $r) {
            $s = $subs->first(fn($x) => $x->endpoint === $r->getEndpoint());
            if ($r->isSuccess()) { $ok++; $s?->forceFill(['ultimo_envio' => now()])->save(); }
            elseif ($r->isSubscriptionExpired()) $s?->delete();
        }
        return $ok;
    }

    // ── Novedades de BigSys ────────────────────────────────────────────
    public function novedades(User $u): array
    {
        $mods = $u->business?->modulosActivos() ?? [];
        $marcas = DB::table('novedad_usuario')->where('user_id', $u->id)->get()->keyBy('novedad_id');
        return Novedad::where('publicada_en', '<=', now())->where('publicada_en', '>=', now()->subDays(120))->latest('publicada_en')->limit(30)->get()
            ->filter(fn($n) => ! $n->modulo || in_array($n->modulo, $mods, true)) // una novedad de un módulo, sólo a quien lo tiene
            ->map(function ($n) use ($marcas) {
                $m = $marcas[$n->id] ?? null;
                return ['id' => $n->id, 'titulo' => $n->titulo, 'texto' => $n->texto, 'url' => $n->url, 'fecha' => $n->publicada_en->format('d/m'), 'leida' => (bool) ($m?->leida_en), 'pospuesta' => $m?->pospuesta_hasta && $m->pospuesta_hasta > today()->toDateString()];
            })->reject(fn($n) => $n['pospuesta'])->values()->all();
    }

    public function sinLeer(User $u): int { return collect($this->novedades($u))->where('leida', false)->count(); }

    public function marcar(User $u, int $id, ?int $posponerDias = null): void
    {
        Novedad::findOrFail($id);
        DB::table('novedad_usuario')->updateOrInsert(['novedad_id' => $id, 'user_id' => $u->id], $posponerDias ? ['pospuesta_hasta' => today()->addDays($posponerDias)->toDateString(), 'leida_en' => null] : ['leida_en' => now(), 'pospuesta_hasta' => null]);
    }

    // ── El parte de la mañana ──────────────────────────────────────────
    public function configManana(Business $b): array { return array_replace(self::MANANA, $b->avisos['manana'] ?? []); }

    public function guardarManana(Business $b, array $d): array
    {
        $c = array_replace($this->configManana($b), array_intersect_key($d, array_flip(['activo', 'hora', 'push', 'whatsapp'])));
        $c['hora'] = preg_match('/^\d{2}:\d{2}$/', (string) $c['hora']) ? $c['hora'] : '08:00';
        $b->update(['avisos' => array_replace($b->avisos ?? [], ['manana' => $c])]);
        return $c;
    }

    // Cómo cerró ayer y qué hay que mirar hoy.
    public function parte(Business $b): array
    {
        $ayer = app(AvisosDuenoService::class)->resumen($b, today()->subDay());
        $hoy = today()->toDateString();
        $q = fn($dir) => Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', $dir)->where('estado', 'emitido')->where('saldo', '>', 0.005)->whereDate('fecha_vto', $hoy);
        $pagar = (float) $q('compra')->sum('saldo'); $cobrar = (float) $q('venta')->sum('saldo');
        $f = fn($v) => '$ ' . number_format($v, 0, ',', '.');
        $lineas = [];
        if ($pagar > 0) $lineas[] = "📤 Hoy vencen pagos por {$f($pagar)}";
        if ($cobrar > 0) $lineas[] = "📥 Hoy vencen cobros por {$f($cobrar)}";
        $texto = $ayer . ($lineas ? "\n\n*Hoy*\n" . implode("\n", $lineas) : "\n\nHoy no vence nada.");
        $corto = trim(strtok(str_replace('*', '', explode("\n", $ayer)[1] ?? ''), "\n")) . ($lineas ? ' · ' . implode(' · ', array_map(fn($l) => mb_substr($l, 2), $lineas)) : '');
        return ['texto' => $texto, 'corto' => $corto];
    }

    // Se llama cada 15 minutos: si ya es la hora y hoy no salió, lo manda al celular del dueño (y por WhatsApp si lo pidió).
    public function enviarParteSiCorresponde(Business $b, bool $forzar = false): bool
    {
        $c = $this->configManana($b);
        if (! $forzar && (! $c['activo'] || ($c['ultimo'] ?? null) === today()->toDateString() || now()->format('H:i') < $c['hora'])) return false;
        $p = $this->parte($b); $dueno = User::find($b->owner_id);
        if ($c['push'] && $dueno) $this->enviar($dueno, "Buen día · {$b->name}", $p['corto'] ?: 'Tu parte de la mañana está listo.', '/dashboard');
        $wa = app(AvisosDuenoService::class)->config($b)['whatsapp'] ?? null;
        if ($c['whatsapp'] && $wa) app(\App\Services\Canales\WhatsappPedidosService::class)->responder($b, $wa, $p['texto']);
        if (! $forzar) $b->update(['avisos' => array_replace($b->avisos ?? [], ['manana' => array_replace($c, ['ultimo' => today()->toDateString()])])]);
        return true;
    }

    // Las alertas críticas también al celular (además del WhatsApp que ya existía).
    public function criticasAlCelular(Business $b): int
    {
        $a = $b->avisos ?? []; $desde = (int) ($a['ultima_critica_push'] ?? 0);
        $nuevas = \App\Models\Alerta::withoutGlobalScopes()->where('business_id', $b->id)->where('severidad', 'critica')->whereNull('resuelta_en')->where('id', '>', $desde)->orderBy('id')->get();
        if ($nuevas->isEmpty()) return 0;
        if ($desde === 0) { $b->update(['avisos' => array_replace($a, ['ultima_critica_push' => $nuevas->max('id')])]); return 0; } // la primera vez no se manda lo viejo
        $dueno = User::find($b->owner_id);
        if ($dueno) foreach ($nuevas->take(3) as $x) $this->enviar($dueno, '🔴 ' . $x->titulo, (string) $x->detalle, $x->url ?: '/dashboard');
        $b->update(['avisos' => array_replace($a, ['ultima_critica_push' => $nuevas->max('id')])]);
        return $nuevas->count();
    }

    // ── Mejoras: lo que no tiene contratado ────────────────────────────
    public function mejoras(Business $b): array
    {
        $activos = $b->modulosActivos();
        $interes = MejoraInteres::where('business_id', $b->id)->pluck('created_at', 'modulo');
        return collect(config('erp.modulos'))->reject(fn($m, $k) => in_array($k, $activos, true) || ! empty($m['core']) || empty($m['disponible']))
            ->map(fn($m, $k) => ['modulo' => $k, 'nombre' => $m['label'], 'icono' => $m['icono'] ?? 'sparkles', 'grupo' => $m['grupo'] ?? null, 'que_hace' => self::QUE_HACE[$k] ?? null, 'interesado' => isset($interes[$k]), 'cuando' => isset($interes[$k]) ? \Carbon\Carbon::parse($interes[$k])->format('d/m/Y') : null])
            ->values()->all();
    }

    public function meInteresa(User $u, string $modulo, ?string $comentario = null): MejoraInteres
    {
        $m = config("erp.modulos.{$modulo}");
        abort_unless($m && ! in_array($modulo, $u->business->modulosActivos(), true), 422, 'Ese módulo no existe o ya lo tenés.');
        $i = MejoraInteres::updateOrCreate(['business_id' => $u->business_id, 'modulo' => $modulo], ['user_id' => $u->id, 'comentario' => $comentario ? mb_substr($comentario, 0, 500) : null, 'atendido_en' => null]);
        AuditLog::registrar('crear', $u->business, "Le interesa {$m['label']}" . ($comentario ? ": {$comentario}" : ''));
        return $i;
    }
}
