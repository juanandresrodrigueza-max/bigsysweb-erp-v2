<?php

namespace App\Services\IA;

use App\Mcp\Catalogo;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

// Fase 111: el equipo le habla a Bigcito por el WhatsApp de la empresa, por texto o por audio. Quien escribe se reconoce por el
// celular cargado en Configuración → Usuarios y actúa con sus permisos: Bigcito propone, y recién con «sí» ejecuta (mismo catálogo
// y misma auditoría que el panel y MCP). La charla se recuerda dos horas. Un número que no es de un usuario sigue como cliente.
class BigcitoWhatsappService
{
    public const TTL_MINUTOS = 120;
    public const SI = '/^\s*(s[ií]|dale|confirm\w*|hacelo|hac[eé]lo|hac[eé]|de una|ok|okey|listo|adelante|bueno|va)\b/iu';
    public const NO = '/^\s*(no|cancel\w*|dej[aá]\w*|par[aá]\w*|mejor no)\b/iu';

    public function __construct(private Catalogo $cat, private BigcitoService $bigcito, private VozFotoService $voz) {}

    public static function activo(Business $b): bool { return (bool) ($b->whatsapp_settings['bigcito_equipo'] ?? true); }

    public static function digitos(?string $tel): string { return preg_replace('/\D/', '', (string) $tel); }

    // El usuario activo de la empresa cuyo celular termina igual que el número que escribe (WhatsApp manda 54 9 + área + número).
    public function usuario(Business $b, ?string $tel): ?User
    {
        $d = self::digitos($tel);
        if (strlen($d) < 8) return null;
        return $b->users()->where('status', 'active')->whereNotNull('mobile')->get()->first(function (User $u) use ($d) {
            $m = self::digitos($u->mobile);
            if (strlen($m) < 8) return false;
            $n = min(10, strlen($m), strlen($d));
            return substr($m, -$n) === substr($d, -$n);
        });
    }

    // Devuelve la respuesta para mandar por WhatsApp, o null si el número no es de un usuario (sigue el circuito de clientes).
    public function atender(Business $b, array $m): ?array
    {
        $u = $this->usuario($b, $m['de'] ?? null);
        if (! $u) return null;
        $texto = trim((string) ($m['texto'] ?? ''));
        $prev = Auth::user();
        Auth::setUser($u);
        try {
            if (! empty($m['audio_id'])) {
                try { $texto = $this->transcribir($b, $m['audio_id'], $m['mime'] ?? 'audio/ogg'); }
                catch (ValidationException $e) { return ['respuesta' => collect($e->errors())->flatten()->implode(' ') . ' Mientras tanto, escribime el mensaje.', 'usuario' => $u->name, 'tipo' => 'audio_sin_texto']; }
                catch (\Throwable $e) { Log::warning('Bigcito WhatsApp: audio', ['e' => $e->getMessage()]); return ['respuesta' => 'No pude escuchar el audio. Probá de nuevo o escribime el mensaje.', 'usuario' => $u->name, 'tipo' => 'audio_error']; }
            }
            if ($texto === '') return ['respuesta' => 'No me llegó texto. Escribime o mandame un audio con lo que necesitás.', 'usuario' => $u->name, 'tipo' => 'vacio'];
            $clave = "bigcito:wa:{$u->id}";
            $estado = Cache::get($clave, ['historial' => [], 'propuesta' => null]);
            $r = $this->procesar($u, $texto, $estado);
            Cache::put($clave, $estado, now()->addMinutes(self::TTL_MINUTOS));
            return $r + ['usuario' => $u->name, 'texto' => $texto];
        } finally {
            if ($prev) Auth::setUser($prev); elseif (method_exists(Auth::guard(), 'forgetUser')) Auth::guard()->forgetUser();
        }
    }

    private function procesar(User $u, string $texto, array &$estado): array
    {
        $pend = $estado['propuesta'] ?? null;
        if ($pend && preg_match(self::SI, $texto)) {
            $estado['propuesta'] = null;
            $r = $this->cat->llamar($u, 'confirmar_accion', ['propuesta_id' => $pend['id']], 'whatsapp');
            $resp = isset($r['error']) ? 'No se pudo: ' . $r['error'] : trim(($r['texto'] ?? 'Listo.') . (! empty($r['url']) ? "\n{$r['url']}" : ''));
            $this->recordar($estado['historial'], $texto, $resp);
            return ['respuesta' => $resp, 'tipo' => 'confirmado', 'ok' => ! isset($r['error'])];
        }
        if ($pend && preg_match(self::NO, $texto)) {
            $estado['propuesta'] = null;
            $this->cat->cancelar($u, $pend['id']);
            $this->recordar($estado['historial'], $texto, 'Listo, no lo hago.');
            return ['respuesta' => 'Listo, no lo hago.', 'tipo' => 'cancelado'];
        }
        $r = $this->bigcito->responder($u, ['mensaje' => $texto, 'historial' => $estado['historial'], 'pantalla' => 'WhatsApp', 'origen' => 'whatsapp']);
        $resp = trim((string) ($r['respuesta'] ?? ''));
        $estado['propuesta'] = null;
        if (! empty($r['propuesta'])) {
            $p = $r['propuesta'];
            $estado['propuesta'] = ['id' => $p['id'], 'titulo' => $p['titulo']];
            $resp = trim($resp . "\n\n➡️ {$p['titulo']}" . (! empty($p['detalle']) ? "\n{$p['detalle']}" : '') . "\n\nRespondé SÍ para hacerlo o NO para cancelar." . (! empty($p['delicada']) ? ' Es una acción delicada: revisá bien antes.' : ''));
        } elseif (! empty($r['tabla']['filas'])) {
            $resp .= "\n(" . count($r['tabla']['filas']) . ' filas; la lista completa está en el sistema.)';
        }
        $this->recordar($estado['historial'], $texto, $resp . ($estado['propuesta'] ? " [propuesta: {$estado['propuesta']['titulo']} → sin confirmar]" : ''));
        return ['respuesta' => $resp !== '' ? $resp : 'No pude responder. Probá de nuevo.', 'tipo' => $estado['propuesta'] ? 'propuesta' : 'respuesta', 'propuesta' => $estado['propuesta']];
    }

    private function recordar(array &$historial, string $pregunta, string $respuesta): void
    {
        $historial[] = ['rol' => 'user', 'texto' => mb_substr($pregunta, 0, 1000)];
        $historial[] = ['rol' => 'assistant', 'texto' => mb_substr($respuesta, 0, 1500)];
        $historial = array_slice($historial, -10);
    }

    // Baja el audio de la Cloud API (id → url → bytes) y lo transcribe con la clave de OpenAI o Gemini de la empresa.
    private function transcribir(Business $b, string $mediaId, string $mime): string
    {
        $token = $b->whatsapp_settings['token'] ?? null;
        if (! $token) throw ValidationException::withMessages(['audio' => 'Falta el token de WhatsApp para bajar el audio.']);
        if (! $this->voz->disponible($b)) throw ValidationException::withMessages(['audio' => 'Para que Bigcito entienda audios hay que cargar una clave de OpenAI o de Gemini en Configuración → Conexiones IA (Claude no lee audio).']);
        $meta = Http::withToken($token)->timeout(15)->get("https://graph.facebook.com/v19.0/{$mediaId}");
        if (! $meta->successful() || ! $meta->json('url')) throw new \RuntimeException('No se pudo ubicar el audio en WhatsApp.');
        $bin = Http::withToken($token)->timeout(30)->get($meta->json('url'));
        if (! $bin->successful()) throw new \RuntimeException('No se pudo bajar el audio de WhatsApp.');
        $mime = trim(explode(';', $meta->json('mime_type') ?: $mime)[0]);
        $path = tempnam(sys_get_temp_dir(), 'wa-audio-');
        file_put_contents($path, $bin->body());
        try { return $this->voz->transcribir($b, $path, $mime); } finally { @unlink($path); }
    }
}
