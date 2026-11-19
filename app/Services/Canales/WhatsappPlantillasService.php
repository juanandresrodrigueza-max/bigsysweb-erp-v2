<?php

namespace App\Services\Canales;

use App\Models\Business;
use App\Models\WhatsappVentana;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Fase 120.1: plantillas aprobadas y ventana de 24 horas.
// Meta sólo deja mandar texto libre por la API a quien le escribió a la empresa en las últimas 24 horas. Para escribirle
// a alguien «en frío» (un recordatorio de deuda, el informe de una rutina, un encargo) hay que usar una plantilla aprobada.
// Este servicio: (1) crea las plantillas de BigSys en la cuenta de WhatsApp de la empresa y sigue su aprobación,
// (2) sabe si la ventana de un número está abierta, y (3) guarda el texto completo para entregarlo cuando la persona conteste.
class WhatsappPlantillasService
{
    public const IDIOMA = 'es_AR';
    public const HORAS_VENTANA = 23.5; // media hora de margen sobre las 24
    public const MAX_PENDIENTES = 8;

    // Las plantillas que usa el ERP. `cuerpo` es lo que se manda a aprobar; `ejemplo`, los valores de muestra que pide Meta.
    public const CATALOGO = [
        'bigsys_aviso' => ['categoria' => 'UTILITY', 'cuerpo' => "Hola {{1}}, tenés un mensaje de {{2}}: {{3}}\n\nRespondé VER para leerlo completo.",
            'ejemplo' => ['Juan', 'Corralón Demo', 'tu pedido está listo para retirar'], 'para' => 'Avisos en general'],
        'bigsys_recordatorio' => ['categoria' => 'UTILITY', 'cuerpo' => "Hola {{1}}, te escribimos de {{2}} para recordarte que tenés un saldo pendiente de {{3}}. Si ya lo abonaste, no tengas en cuenta este mensaje.\n\nRespondé a este WhatsApp y lo vemos juntos.",
            'ejemplo' => ['Juan', 'Corralón Demo', '$ 125.000'], 'para' => 'Recordatorios de deuda'],
        'bigsys_informe' => ['categoria' => 'UTILITY', 'cuerpo' => "Hola {{1}}, Bigcito tiene listo tu informe «{{2}}» de {{3}}.\n\nRespondé VER para recibirlo.",
            'ejemplo' => ['Juan', 'Resumen del lunes', 'Corralón Demo'], 'para' => 'Rutinas de Bigcito'],
        'bigsys_encargo' => ['categoria' => 'UTILITY', 'cuerpo' => "Hola {{1}}, {{2}} te dejó un encargo en {{3}}: {{4}}\n\nRespondé HECHO cuando lo termines, o VER para más detalle.",
            'ejemplo' => ['Pedro', 'Juan', 'Corralón Demo', 'preparar el pedido 12 para las 17'], 'para' => 'Encargos entre personas del equipo'],
    ];

    public static function clave(?string $tel): string { return substr(preg_replace('/\D/', '', (string) $tel), -10); }

    // ── Plantillas ─────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function conectado(Business $b): bool { $ws = $b->whatsapp_settings ?? []; return ! empty($ws['token']) && ! empty($ws['phone_id']); }
    public function puedePlantillas(Business $b): bool { return $this->conectado($b) && ! empty($b->whatsapp_settings['waba_id']); }

    // Lo que muestra la pantalla: cada plantilla con su estado (aprobada, en revisión, rechazada, sin crear).
    public function estado(Business $b): array
    {
        $ws = $b->whatsapp_settings ?? []; $est = $ws['plantillas'] ?? [];
        return ['disponible' => $this->puedePlantillas($b), 'conectado' => $this->conectado($b), 'revisado' => $ws['plantillas_en'] ?? null,
            'plantillas' => collect(self::CATALOGO)->map(fn($p, $n) => ['nombre' => $n, 'para' => $p['para'], 'estado' => $est[$n]['estado'] ?? 'sin_crear', 'motivo' => $est[$n]['motivo'] ?? null])->values()->all(),
            'aprobadas' => collect(self::CATALOGO)->keys()->filter(fn($n) => ($est[$n]['estado'] ?? '') === 'aprobada')->count()];
    }

    public function aprobada(Business $b, string $nombre): bool { return (($b->whatsapp_settings['plantillas'][$nombre]['estado'] ?? '') === 'aprobada') && $this->conectado($b); }

    // Crea en la cuenta de WhatsApp de la empresa las plantillas que falten y actualiza el estado de todas.
    public function sincronizar(Business $b): array
    {
        if (! $this->puedePlantillas($b)) return ['error' => $this->conectado($b) ? 'Falta el identificador de la cuenta de WhatsApp Business (WABA). Conectá WhatsApp con el QR o cargalo en «Avanzado».' : 'Primero conectá WhatsApp.'];
        $ws = $b->whatsapp_settings; $base = WhatsappConexionService::GRAPH . "/{$ws['waba_id']}/message_templates";
        try {
            $r = Http::withToken($ws['token'])->timeout(20)->get($base, ['fields' => 'name,status,language,rejected_reason', 'limit' => 200]);
            if (! $r->successful()) return ['error' => 'Meta no dejó ver las plantillas: ' . ($r->json('error.message') ?? $r->status())];
            $hay = collect((array) $r->json('data', []))->where('language', self::IDIOMA)->keyBy('name');
            $est = []; $creadas = 0; $errores = [];
            foreach (self::CATALOGO as $nombre => $p) {
                $x = $hay[$nombre] ?? null;
                if (! $x) {
                    $c = Http::withToken($ws['token'])->timeout(20)->post($base, ['name' => $nombre, 'language' => self::IDIOMA, 'category' => $p['categoria'],
                        'components' => [['type' => 'BODY', 'text' => $p['cuerpo'], 'example' => ['body_text' => [$p['ejemplo']]]]]]);
                    if ($c->successful()) { $creadas++; $est[$nombre] = ['estado' => self::traducir((string) ($c->json('status') ?? 'PENDING')), 'motivo' => null]; }
                    else { $errores[] = "{$nombre}: " . ($c->json('error.error_user_msg') ?? $c->json('error.message') ?? $c->status()); $est[$nombre] = ['estado' => 'error', 'motivo' => mb_substr((string) ($c->json('error.error_user_msg') ?? $c->json('error.message') ?? ''), 0, 200)]; }
                    continue;
                }
                $est[$nombre] = ['estado' => self::traducir((string) ($x['status'] ?? '')), 'motivo' => ! empty($x['rejected_reason']) && $x['rejected_reason'] !== 'NONE' ? (string) $x['rejected_reason'] : null];
            }
        } catch (\Throwable $e) { report($e); return ['error' => 'No se pudo conectar con Meta: ' . mb_substr($e->getMessage(), 0, 120)]; }
        $b->update(['whatsapp_settings' => array_merge($b->fresh()->whatsapp_settings ?? [], ['plantillas' => $est, 'plantillas_en' => now()->toDateTimeString()])]);
        return ['creadas' => $creadas, 'aprobadas' => collect($est)->where('estado', 'aprobada')->count(), 'en_revision' => collect($est)->where('estado', 'en_revision')->count(), 'errores' => $errores];
    }

    private static function traducir(string $s): string
    {
        return match (strtoupper($s)) { 'APPROVED' => 'aprobada', 'PENDING', 'IN_APPEAL', 'PENDING_DELETION' => 'en_revision', 'REJECTED' => 'rechazada', 'PAUSED', 'DISABLED' => 'pausada', default => 'en_revision' };
    }

    // Un parámetro de plantilla no puede tener saltos de línea, tabulaciones ni más de 4 espacios seguidos.
    public static function param($v, int $max = 300): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $v));
        return $t === '' ? '-' : mb_substr($t, 0, $max);
    }

    // Manda una plantilla aprobada. Devuelve el mismo formato que WhatsappPedidosService::responder.
    public function enviar(Business $b, string $telefono, string $nombre, array $params): array
    {
        $ws = $b->whatsapp_settings ?? [];
        if (! $this->aprobada($b, $nombre)) return ['enviado' => false, 'link' => null, 'error' => "La plantilla {$nombre} todavía no está aprobada por Meta."];
        try {
            $r = Http::withToken($ws['token'])->timeout(10)->post("https://graph.facebook.com/v19.0/{$ws['phone_id']}/messages", ['messaging_product' => 'whatsapp', 'to' => preg_replace('/\D/', '', $telefono), 'type' => 'template',
                'template' => ['name' => $nombre, 'language' => ['code' => self::IDIOMA], 'components' => [['type' => 'body', 'parameters' => array_map(fn($p) => ['type' => 'text', 'text' => self::param($p)], array_values($params))]]]]);
            return ['enviado' => $r->successful(), 'link' => null, 'via' => 'plantilla', 'plantilla' => $nombre, 'wamid' => $r->json('messages.0.id'), 'error' => $r->successful() ? null : 'Meta respondió ' . $r->status() . ': ' . ($r->json('error.message') ?? mb_substr($r->body(), 0, 120))];
        } catch (\Throwable $e) { return ['enviado' => false, 'link' => null, 'error' => 'No se pudo conectar con Meta: ' . mb_substr($e->getMessage(), 0, 120)]; }
    }

    // ── Ventana de 24 horas ────────────────────────────────────────────────────────────────────────────────────────────────

    private function fila(Business $b, string $tel, bool $crear = false): ?WhatsappVentana
    {
        $k = self::clave($tel); if (strlen($k) < 8) return null;
        return $crear ? WhatsappVentana::firstOrCreate(['business_id' => $b->id, 'telefono' => $k]) : WhatsappVentana::where('business_id', $b->id)->where('telefono', $k)->first();
    }

    public function abierta(Business $b, string $tel): bool
    {
        $f = $this->fila($b, $tel);
        return $f && $f->ultimo_entrante_en && $f->ultimo_entrante_en->gt(now()->subMinutes((int) (self::HORAS_VENTANA * 60)));
    }

    // Llegó un mensaje de ese número: se abre la ventana y se devuelve lo que tenía pendiente de recibir (y se limpia).
    public function registrarEntrante(Business $b, string $tel): array
    {
        $f = $this->fila($b, $tel, true); if (! $f) return [];
        $pend = array_values(array_filter(array_map(fn($p) => is_array($p) ? ($p['texto'] ?? null) : $p, (array) $f->pendientes)));
        $f->update(['ultimo_entrante_en' => now(), 'pendientes' => null]);
        return $pend;
    }

    public function guardarPendiente(Business $b, string $tel, string $texto): void
    {
        $f = $this->fila($b, $tel, true); if (! $f) return;
        $p = (array) $f->pendientes; $p[] = ['texto' => mb_substr($texto, 0, 3500), 'en' => now()->toDateTimeString()];
        $f->update(['pendientes' => array_slice($p, -self::MAX_PENDIENTES)]);
    }

    public function anotarSaliente(Business $b, string $tel, ?string $wamid, string $texto, array $opt = []): void
    {
        if (! $wamid) return;
        $f = $this->fila($b, $tel, true); if (! $f) return;
        $f->update(['ultimo_saliente' => ['wamid' => $wamid, 'texto' => mb_substr($texto, 0, 3500), 'opt' => array_intersect_key($opt, ['plantilla' => 1, 'params' => 1, 'nombre' => 1]), 'en' => now()->toDateTimeString()]]);
    }

    // Meta avisó que un mensaje de texto no se entregó porque la ventana estaba cerrada (código 131047): se cierra la ventana
    // y, si era el último que mandamos a ese número, se reintenta con plantilla. Devuelve true si lo reintentó.
    public function rebotado(Business $b, string $tel, ?string $wamid): bool
    {
        $f = $this->fila($b, $tel); if (! $f) return false;
        $ult = (array) $f->ultimo_saliente;
        $f->update(['ultimo_entrante_en' => null, 'ultimo_saliente' => null]);
        if (! $wamid || ($ult['wamid'] ?? null) !== $wamid || empty($ult['texto'])) return false;
        $r = app(WhatsappPedidosService::class)->responder($b, $tel, $ult['texto'], (array) ($ult['opt'] ?? []));
        if (empty($r['enviado'])) Log::warning('WhatsApp: no se pudo reintentar con plantilla', ['empresa' => $b->id, 'error' => $r['error'] ?? null]);
        return ! empty($r['enviado']);
    }

    // Qué plantilla usar y con qué datos para un texto libre que hay que mandar con la ventana cerrada. null si no hay ninguna aprobada.
    public function elegir(Business $b, string $texto, array $opt): ?array
    {
        $nombre = self::param($opt['nombre'] ?? 'cómo estás', 60); $empresa = self::param($b->name, 80);
        $pedida = $opt['plantilla'] ?? null;
        if ($pedida && $this->aprobada($b, $pedida) && ! empty($opt['params'])) return ['plantilla' => $pedida, 'params' => array_values($opt['params'])];
        if ($this->aprobada($b, 'bigsys_aviso')) {
            $resumen = self::param(preg_replace('/[*_~`]/u', '', strtok(trim($texto), "\n") ?: $texto), 120);
            return ['plantilla' => 'bigsys_aviso', 'params' => [$nombre, $empresa, $resumen]];
        }
        return null;
    }
}
