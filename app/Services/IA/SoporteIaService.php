<?php

namespace App\Services\IA;

use App\Models\SistemaConfig;
use App\Models\Ticket;
use App\Services\Producto\AyudaService;
use App\Support\Ia;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Fase 67 · Bigcito atiende primero los tickets de soporte: busca en las guías del sistema, responde con los pasos y las fuentes,
// y si el manual no lo cubre lo dice y se lo pasa al equipo de BigSys. La clave de IA es la del sistema (BigSys paga el soporte);
// si no hay, la de la empresa; si no hay ninguna, responde con las guías que coinciden.
class SoporteIaService
{
    public const ESCALADO = 'Le paso tu consulta al equipo de BigSys: te responden acá y por mail a la brevedad.';
    private const PIDE_PERSONA = '/\b(no me sirvi[oó]|no funcion[oó]|no me ayud[oó]|sigue (igual|sin)|quiero hablar|una persona|un humano|alguien de soporte|que me llamen)\b/iu';

    public function __construct(private AyudaService $ayuda) {}

    // Al abrir el ticket: la respuesta de Bigcito (o la derivación). Devuelve el mensaje agregado.
    public function atender(Ticket $t, string $pregunta): array
    {
        $fuentes = $this->ayuda->buscar($pregunta, 6);
        if (! $fuentes || in_array($t->categoria, ['facturacion', 'sugerencia'], true)) return $this->derivar($t, $t->categoria === 'sugerencia' ? 'Gracias por la sugerencia: se la paso al equipo de BigSys para que la mire.' : ($t->categoria === 'facturacion' ? 'Lo de facturación y planes lo ve una persona del equipo de BigSys: te responde acá y por mail.' : 'No encontré esto en las guías del sistema. ' . self::ESCALADO));
        $texto = null; $modo = 'guias';
        if ($this->clave($t)) {
            $ctx = collect($fuentes)->map(function ($f) { $sec = collect($this->ayuda->secciones($this->ayuda->articulos()[$f['slug']]['texto'] ?? ''))->firstWhere('ancla', $f['ancla']); return "### {$f['titulo']} › {$f['seccion']}\n" . mb_substr($sec['texto'] ?? $f['fragmento'], 0, 2500); })->implode("\n\n");
            try {
                $r = Http::withHeaders(['x-api-key' => config('services.anthropic.api_key'), 'anthropic-version' => '2023-06-01'])->timeout(30)->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model'), 'max_tokens' => 600,
                    'system' => "Sos Bigcito, el soporte de primera línea de BigSysWeb (ERP para PyMEs argentinas). Un usuario abrió un ticket. Respondé SOLO con lo que dicen las guías que te paso: pasos concretos (menú → pantalla → botón), en español rioplatense, corto y amable, sin inventar pantallas ni opciones. Si las guías no resuelven de verdad lo que pregunta (o es un error del sistema, un dato mal calculado, algo roto), respondé exactamente la palabra ESCALAR y nada más.",
                    'messages' => [['role' => 'user', 'content' => "Guías:\n\n{$ctx}\n\nTicket «{$t->asunto}» ({$t->categoria}):\n{$pregunta}"]]]);
                $texto = $r->successful() ? trim(collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode("\n")) : null; $modo = 'ia';
            } catch (\Throwable $e) { Log::warning('Soporte IA: ' . $e->getMessage()); $texto = null; }
            if ($texto !== null && (str_starts_with(strtoupper($texto), 'ESCALAR') || $texto === '')) return $this->derivar($t, 'Esto no lo resuelven las guías del sistema. ' . self::ESCALADO);
        }
        if ($texto === null || $modo === 'guias') { $modo = 'guias'; $texto = "Mientras te responde el equipo, esto de las guías puede servirte:\n" . collect($fuentes)->take(3)->map(fn($f) => "• {$f['titulo']} › {$f['seccion']}: {$f['fragmento']}")->implode("\n"); }
        $texto .= "\n\nSi te sirvió, cerrá el ticket; si no, decime «no me sirvió» y te responde una persona.";
        return $this->agregar($t, $texto, ['modo' => $modo, 'fuentes' => collect($fuentes)->take(3)->map(fn($f) => ['titulo' => $f['titulo'] . ' › ' . $f['seccion'], 'url' => $f['url']])->values()->all(), 'escalado' => false]);
    }

    // Cuando el usuario sigue la charla: si dice que no le sirvió (o pide una persona) se deriva; si no, Bigcito no se mete más (responde el equipo).
    public function seguimiento(Ticket $t, string $texto): ?array
    {
        $ultimo = collect($t->mensajes)->last(); // el que acaba de escribir el usuario
        $previo = collect($t->mensajes)->slice(0, -1)->last();
        if (($previo['de'] ?? null) !== 'bigcito' || ! empty($previo['escalado'])) return null;
        if (preg_match(self::PIDE_PERSONA, $texto)) return $this->derivar($t, 'Entendido, perdoná. ' . self::ESCALADO);
        return null;
    }

    private function derivar(Ticket $t, string $texto): array
    {
        $m = $this->agregar($t, $texto, ['modo' => 'derivado', 'fuentes' => [], 'escalado' => true]);
        if ($t->prioridad === 'baja') $t->update(['prioridad' => 'normal']);
        return $m;
    }

    private function agregar(Ticket $t, string $texto, array $meta): array
    {
        $m = $t->mensajes ?? [];
        $msg = ['de' => 'bigcito', 'usuario' => 'Bigcito', 'texto' => $texto, 'fecha' => now()->toDateTimeString()] + $meta;
        $m[] = $msg; $t->mensajes = $m; $t->ultimo_mensaje_en = now(); $t->estado = 'abierto'; $t->save();
        return $msg;
    }

    // La clave: primero la del sistema (panel de BigSys), si no la de la empresa del ticket.
    private function clave(Ticket $t): bool
    {
        SistemaConfig::aplicar();
        if (config('services.anthropic.api_key')) return true;
        $b = \App\Models\Business::find($t->business_id);
        if ($b && Ia::tiene($b)) { Ia::aplicar($b); return true; }
        return false;
    }

    // Cuántos tickets abiertos esperan que el usuario diga si le sirvió lo de Bigcito (para el panel de BigSys).
    public static function esperandoConfirmacion($tickets): int
    {
        return collect($tickets)->filter(fn($t) => $t->estado === 'abierto' && (($u = collect($t->mensajes)->last()) && ($u['de'] ?? '') === 'bigcito' && empty($u['escalado'])))->count();
    }
}
