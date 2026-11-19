<?php

namespace App\Services\IA;

use App\Models\Business;
use App\Models\SistemaConfig;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// Fase 120.4: conversación en vivo con Bigcito. El navegador abre una llamada de audio directa con el modelo de voz
// (OpenAI Realtime por WebRTC): se habla y se escucha a la vez, y se lo puede interrumpir. El modelo de voz no sabe nada
// del negocio: para cada pedido llama a la herramienta `bigcito`, que es el mismo Bigcito de siempre (/agente/chat), con sus
// permisos y sus propuestas que esperan confirmación. Acá sólo se arma la sesión y se entrega una llave de un solo uso:
// la clave real nunca llega al navegador.
class VozVivoService
{
    public const VOCES = ['marin', 'cedar', 'alloy', 'ash', 'ballad', 'coral', 'echo', 'sage', 'shimmer', 'verse'];
    private const API = 'https://api.openai.com/v1/realtime/client_secrets';

    public function __construct(private VozFotoService $voz) {}

    public static function config(): array
    {
        return [
            'activa' => (bool) SistemaConfig::get('voz_vivo'),
            'modelo' => SistemaConfig::get('voz_vivo_modelo') ?: 'gpt-realtime',
            'minutos' => max(1, min(30, (int) (SistemaConfig::get('voz_vivo_minutos') ?: 5))),
            'diarias' => max(1, (int) (SistemaConfig::get('voz_vivo_diarias') ?: 30)),
        ];
    }

    // Hay conversación en vivo si BigSys la prendió y la voz de esta empresa (la suya o la del sistema) es de OpenAI.
    public function disponible(?Business $b): bool
    {
        try { if (! self::config()['activa']) return false; } catch (\Throwable $e) { return false; }
        $k = $this->voz->clave($b);
        return $k && $k['proveedor'] === 'openai';
    }

    // Lo que el modelo de voz sabe y puede: hablar como Bigcito y delegar todo en la herramienta.
    public function sesion(User $u): array
    {
        $b = $u->business; $c = self::config(); $k = $this->voz->clave($b);
        $voz = in_array($k['voz'] ?? null, self::VOCES, true) ? $k['voz'] : 'marin';
        $nombre = explode(' ', trim((string) $u->name))[0] ?: 'la persona';
        $instr = "Sos Bigcito, el asistente de voz de «{$b->name}» dentro del sistema de gestión BigSysWeb. Estás en una llamada con {$nombre}.\n"
            . "Hablás en español rioplatense (Argentina), de vos, con frases cortas, tono cálido y directo. Nada de listas largas ni de leer símbolos.\n\n"
            . "Reglas:\n"
            . "1. No sabés nada del negocio por tu cuenta. Para CUALQUIER dato, consulta o acción (ventas, clientes, stock, cobrar, facturar, gastos, abrir una pantalla, lo que sea) llamá a la herramienta `bigcito` pasándole el pedido completo tal como lo dijo la persona, con los nombres y los números. Nunca inventes datos y nunca digas que hiciste algo sin haber llamado a la herramienta.\n"
            . "2. Lo que devuelve `bigcito` es la respuesta: decila resumida en una o dos frases, con los números como se dicen hablando. Si es una lista larga, decí lo más importante y avisá que el detalle quedó en la pantalla.\n"
            . "3. Si la respuesta trae `espera_confirmacion` en verdadero, contá en una frase qué se va a hacer y preguntá «¿Lo confirmo?». Sólo si la persona dice claramente que sí, llamá a `confirmar`. Si dice que no o duda, llamá a `cancelar`. Nunca llames a `confirmar` sin un sí claro.\n"
            . "4. Si la persona se despide o dice «nada más», despedite en pocas palabras y llamá a `colgar`.\n"
            . "5. Si no entendiste, pedí que lo repita. Mientras esperás a la herramienta podés decir «un segundo».";
        $vacio = ['type' => 'object', 'properties' => new \stdClass, 'required' => []];
        return [
            'type' => 'realtime', 'model' => $c['modelo'], 'instructions' => $instr,
            'audio' => ['input' => ['transcription' => ['model' => 'gpt-4o-mini-transcribe', 'language' => 'es'], 'turn_detection' => ['type' => 'server_vad']], 'output' => ['voice' => $voz]],
            'tools' => [
                ['type' => 'function', 'name' => 'bigcito', 'description' => 'Consulta datos del negocio o prepara una acción en el sistema. Devuelve la respuesta y, si es una acción, queda esperando confirmación.', 'parameters' => ['type' => 'object', 'properties' => ['pedido' => ['type' => 'string', 'description' => 'El pedido completo de la persona, en sus palabras, con nombres y números.']], 'required' => ['pedido']]],
                ['type' => 'function', 'name' => 'confirmar', 'description' => 'Ejecuta lo que quedó esperando confirmación. Sólo después de un sí claro de la persona.', 'parameters' => $vacio],
                ['type' => 'function', 'name' => 'cancelar', 'description' => 'Descarta lo que quedó esperando confirmación.', 'parameters' => $vacio],
                ['type' => 'function', 'name' => 'colgar', 'description' => 'Termina la llamada cuando la persona se despide.', 'parameters' => $vacio],
            ],
            'tool_choice' => 'auto',
        ];
    }

    // Pide la llave de un solo uso para que el navegador abra la llamada. ['ok' => true, 'token', 'modelo', 'max_segundos', 'sesion'?] o ['ok' => false, 'error'].
    public function abrir(User $u): array
    {
        $b = $u->business;
        if (! $this->disponible($b)) return ['ok' => false, 'error' => 'La conversación en vivo no está activada.'];
        $c = self::config(); $k = $this->voz->clave($b);
        $cuenta = 'vivo:dia:' . $u->id . ':' . now()->toDateString();
        if ((int) Cache::get($cuenta, 0) >= $c['diarias']) return ['ok' => false, 'error' => 'Ya usaste todas las conversaciones en vivo de hoy. Podés seguir hablándole con el micrófono de siempre.'];

        $sesion = $this->sesion($u); $mandarSesion = false;
        try {
            $r = Http::withToken($k['api_key'])->timeout(20)->post(self::API, ['session' => $sesion, 'expires_after' => ['anchor' => 'created_at', 'seconds' => 120]]);
            if ($r->clientError() && ! in_array($r->status(), [401, 403, 429], true)) {
                // Si el servicio no acepta algún detalle de la sesión, se pide la llave pelada y la sesión se manda al abrir la llamada.
                $r = Http::withToken($k['api_key'])->timeout(20)->post(self::API, ['session' => ['type' => 'realtime', 'model' => $c['modelo']]]);
                $mandarSesion = true;
            }
        } catch (\Throwable $e) { report($e); return ['ok' => false, 'error' => 'No se pudo abrir la conversación en vivo. Probá de nuevo en un momento.']; }
        $token = $r->json('value') ?? $r->json('client_secret.value');
        if (! $r->successful() || ! $token) {
            \Illuminate\Support\Facades\Log::warning('Voz en vivo: no se pudo pedir la llave', ['status' => $r->status(), 'error' => mb_substr((string) ($r->json('error.message') ?? $r->body()), 0, 300)]);
            return ['ok' => false, 'error' => match ($r->status()) { 401, 403 => 'La clave de voz no tiene permiso para la conversación en vivo.', 429 => 'El servicio de voz está saturado o sin saldo. Probá más tarde.', default => 'No se pudo abrir la conversación en vivo.' }];
        }
        Cache::add($cuenta, 0, now()->endOfDay()); Cache::increment($cuenta);
        return ['ok' => true, 'token' => $token, 'modelo' => $c['modelo'], 'max_segundos' => $c['minutos'] * 60] + ($mandarSesion ? ['sesion' => $sesion] : []);
    }

    // Al cortar, el navegador avisa cuánto duró: queda la cuenta del mes para ver el uso en Panel → Sistema.
    public function anotar(User $u, int $segundos): void
    {
        $segundos = max(0, min(self::config()['minutos'] * 60 + 30, $segundos));
        if (! $segundos) return;
        $clave = 'vivo:seg:' . now()->format('Y-m');
        Cache::add($clave, 0, now()->addDays(70)); Cache::increment($clave, $segundos);
    }

    public static function minutosDelMes(): int { return (int) round(((int) Cache::get('vivo:seg:' . now()->format('Y-m'), 0)) / 60); }
}
