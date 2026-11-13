<?php

namespace App\Services\Gastronomia;

use App\Models\Alerta;
use App\Models\Booking;
use App\Models\Business;
use App\Services\Canales\TiendaService;
use App\Services\Canales\WhatsappPedidosService;
use Carbon\Carbon;
use Illuminate\Support\Str;

// Fase 91: reservas que entran por WhatsApp (o por Bigcito) con lista de espera cuando no hay lugar, y el aviso cuando se libera.
class ReservasService
{
    public const VENTANA = 90; // minutos a cada lado: dos turnos no se pisan si están a más de hora y media

    public function capacidad(Business $b): int
    {
        return (int) (app(TiendaService::class)->config($b)['reservas_capacidad'] ?? 0) ?: 40;
    }

    public function ocupadas(Business $b, Carbon $inicio): int
    {
        return (int) Booking::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('status', ['pending', 'confirmed'])
            ->whereBetween('starts_at', [$inicio->copy()->subMinutes(self::VENTANA), $inicio->copy()->addMinutes(self::VENTANA)])->sum('personas');
    }

    public function hayLugar(Business $b, Carbon $inicio, int $personas): bool
    {
        return $this->ocupadas($b, $inicio) + $personas <= $this->capacidad($b);
    }

    // Reserva confirmada si entra; si no, queda en lista de espera y se avisa al salón.
    public function reservar(Business $b, array $d): Booking
    {
        $inicio = $d['inicio'] instanceof Carbon ? $d['inicio'] : Carbon::parse($d['inicio']);
        $lugar = $this->hayLugar($b, $inicio, (int) $d['personas']);
        $r = Booking::create(['business_id' => $b->id, 'location_id' => $d['location_id'] ?? $b->locations()->where('is_default', true)->value('id'), 'starts_at' => $inicio, 'ends_at' => $inicio->copy()->addHours(2),
            'status' => $lugar ? 'confirmed' : 'waitlist', 'price' => 0, 'notes' => $d['notas'] ?? null, 'nombre' => $d['nombre'] ?: 'Sin nombre', 'telefono' => $d['telefono'] ?? null, 'personas' => (int) $d['personas'], 'origen' => $d['origen'] ?? 'whatsapp', 'token' => Str::random(24)]);
        Alerta::emitir(['business_id' => $b->id, 'modulo' => 'gastronomia', 'tipo' => $lugar ? 'reserva_nueva' : 'reserva_espera', 'modelo' => 'Booking', 'modelo_id' => $r->id, 'severidad' => 'info',
            'titulo' => ($lugar ? 'Reserva por ' . ($r->origen === 'bigcito' ? 'Bigcito' : 'WhatsApp') . ': ' : 'Lista de espera: ') . "{$r->nombre} · {$r->personas} personas",
            'detalle' => $inicio->format('d/m H:i') . ($r->telefono ? " · {$r->telefono}" : '') . ($lugar ? '.' : '. No había lugar a esa hora: si se libera, se le avisa solo.'), 'url' => '/gastronomia/reservas']);
        return $r;
    }

    // Se canceló una reserva: la primera de la lista de espera que entra en ese horario pasa a confirmada y se le avisa por WhatsApp.
    public function liberar(Business $b, Booking $cancelada): array
    {
        $esperan = Booking::withoutGlobalScopes()->where('business_id', $b->id)->where('status', 'waitlist')
            ->whereBetween('starts_at', [$cancelada->starts_at->copy()->subMinutes(self::VENTANA), $cancelada->starts_at->copy()->addMinutes(self::VENTANA)])->orderBy('created_at')->get();
        $confirmadas = [];
        foreach ($esperan as $e) {
            if (! $this->hayLugar($b, $e->starts_at, (int) $e->personas)) continue;
            $e->update(['status' => 'confirmed']);
            $confirmadas[] = $e;
            if ($e->telefono) try { app(WhatsappPedidosService::class)->responder($b, $e->telefono, "¡Hola {$e->nombre}! Se liberó lugar en {$b->name}: tu reserva para {$e->personas} el " . $this->cuando($e->starts_at) . " quedó confirmada. ¡Te esperamos! Si no podés venir, avisanos por acá."); } catch (\Throwable) {}
            Alerta::emitir(['business_id' => $b->id, 'modulo' => 'gastronomia', 'tipo' => 'reserva_nueva', 'modelo' => 'Booking', 'modelo_id' => $e->id, 'severidad' => 'info', 'titulo' => "De la lista de espera: {$e->nombre} · {$e->personas} personas", 'detalle' => $e->starts_at->format('d/m H:i') . '. Se liberó lugar y quedó confirmada; ya se le avisó.', 'url' => '/gastronomia/reservas']);
        }
        return $confirmadas;
    }

    public static function esReserva(string $texto): bool
    {
        $t = self::norm($texto);
        return (bool) preg_match('/\b(reserv|mesa para|lugar para|tienen lugar|tenes lugar|hay lugar|quiero ir a cenar|quiero ir a comer|para cenar|para almorzar|somos \d+|una mesa)/', $t);
    }

    private static function norm(string $t): string
    {
        return ' ' . preg_replace('/\s+/', ' ', Str::lower(Str::ascii($t))) . ' ';
    }

    // "Quiero reservar para 4 el sábado a las 21, soy Juan" → personas, cuándo, nombre. Sin IA: con reglas, para que ande siempre igual.
    public static function interpretar(string $texto, ?Carbon $ahora = null): array
    {
        $ahora ??= now(); $t = self::norm($texto);
        $nums = ['un' => 1, 'uno' => 1, 'una' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10, 'once' => 11, 'doce' => 12, 'quince' => 15, 'veinte' => 20];
        $n = fn($s) => is_numeric($s) ? (int) $s : ($nums[$s] ?? null);
        $personas = null;
        if (preg_match('/\b(?:para|somos|mesa para|lugar para)\s+(\d{1,2}|' . implode('|', array_keys($nums)) . ')\b(?!\s*(?:hs|h\b|horas|:\d|de la|del|\/))/', $t, $m)) $personas = $n($m[1]);
        if (! $personas && preg_match('/\b(\d{1,2}|' . implode('|', array_keys($nums)) . ')\s*(?:personas|pers\b|gente|cubiertos|comensales|adultos)/', $t, $m)) $personas = $n($m[1]);
        // Cuándo.
        $fecha = null; $dias = ['domingo' => 0, 'lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6];
        if (preg_match('/\bpasado manana\b/', $t)) $fecha = $ahora->copy()->addDays(2)->startOfDay();
        elseif (preg_match('/\bmanana\b/', $t) && ! preg_match('/\b(de|a la|por la) manana\b/', $t)) $fecha = $ahora->copy()->addDay()->startOfDay();
        elseif (preg_match('/\bhoy\b|\besta noche\b/', $t)) $fecha = $ahora->copy()->startOfDay();
        elseif (preg_match('/\b(' . implode('|', array_keys($dias)) . ')\b/', $t, $m)) { $fecha = $ahora->copy()->startOfDay(); while ($fecha->dayOfWeek !== $dias[$m[1]]) $fecha->addDay(); if ($fecha->isToday() && preg_match('/\bque viene\b|\bproximo\b/', $t)) $fecha->addWeek(); }
        elseif (preg_match('/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/', $t, $m)) { try { $fecha = Carbon::create(isset($m[3]) ? (int) (strlen($m[3]) === 2 ? '20' . $m[3] : $m[3]) : $ahora->year, (int) $m[2], (int) $m[1])->startOfDay(); if ($fecha->lt($ahora->copy()->startOfDay()) && ! isset($m[3])) $fecha->addYear(); } catch (\Throwable) { $fecha = null; } }
        elseif (preg_match('/\bel (\d{1,2})\b(?!\s*(?:hs|h\b|horas|:|\/))/', $t, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 31) { $fecha = $ahora->copy()->startOfDay()->day(min((int) $m[1], $ahora->daysInMonth)); if ($fecha->lt($ahora->copy()->startOfDay())) $fecha->addMonthNoOverflow(); }
        if (! $fecha && preg_match('/\bal mediodia\b|\ba la noche\b/', $t)) $fecha = $ahora->copy()->startOfDay();
        // Hora.
        $hora = null; $min = 0;
        if (preg_match('/\b(?:a las|a la|las|tipo|como a las)\s*(\d{1,2})(?:[:.h](\d{2}))?\b/', $t, $m) || preg_match('/\b(\d{1,2})(?:[:.](\d{2}))?\s*(?:hs|h\b|horas)/', $t, $m)) { $hora = (int) $m[1]; $min = (int) ($m[2] ?? 0); }
        if ($hora !== null && $hora >= 1 && $hora <= 11 && ! preg_match('/\b(de la manana|am\b|a la manana|desayun)/', $t)) $hora += 12; // "a las 9" en un restaurante es la noche
        if ($hora === null) $hora = preg_match('/\b(mediodia|almorzar|almuerzo)\b/', $t) ? 13 : 21;
        $inicio = $fecha ? $fecha->copy()->setTime(min(23, $hora % 24), $min) : null;
        if ($inicio && $inicio->lt($ahora) && ! $fecha->isToday()) $inicio = null;
        if ($inicio && $inicio->lt($ahora) && $fecha->isToday()) $inicio = $inicio->addDay(); // "hoy a las 9" ya pasadas las 21 → mañana
        $nombre = null;
        if (preg_match('/(?:^|\s)(?:soy|a nombre de|me llamo|mi nombre es)\s+([a-záéíóúñü]+(?:\s+[a-záéíóúñü]+)?)/u', ' ' . Str::lower($texto) . ' ', $m)) $nombre = Str::title(trim($m[1]));
        return ['personas' => $personas, 'inicio' => $inicio, 'nombre' => $nombre, 'pide_datos' => ! $personas || ! $inicio];
    }

    public function cuando(Carbon $c): string
    {
        return $c->locale('es')->isoFormat('dddd D [de] MMMM [a las] HH:mm');
    }

    // Lo que se le contesta por WhatsApp a quien pide mesa. Null si el mensaje no es una reserva.
    public function responderWhatsapp(Business $b, ?string $telefono, string $texto): ?array
    {
        if (! self::esReserva($texto)) return null;
        $i = self::interpretar($texto);
        if ($i['pide_datos']) return ['reserva' => null, 'respuesta' => "¡Hola! Con gusto te reservo en {$b->name}. Decime para cuántos, qué día y a qué hora (por ejemplo: «para 4 el sábado a las 21»)."];
        $nombre = $i['nombre'] ?: ($telefono ? app(\App\Services\Canales\AtencionClientesService::class)->cliente($b, $telefono)?->name : null);
        $r = $this->reservar($b, ['nombre' => $nombre ?: 'WhatsApp ' . ($telefono ?: ''), 'telefono' => $telefono, 'inicio' => $i['inicio'], 'personas' => $i['personas'], 'origen' => 'whatsapp', 'notas' => mb_substr($texto, 0, 300)]);
        $cuando = $this->cuando($r->starts_at);
        $txt = $r->status === 'confirmed'
            ? "¡Listo! Mesa para {$r->personas} el {$cuando} en {$b->name}" . ($i['nombre'] ? " a nombre de {$i['nombre']}" : '') . ". Si no podés venir, avisanos por acá. ¡Te esperamos!"
            : "Uy, a esa hora estamos completos. Te anoté en la lista de espera para {$r->personas} el {$cuando}: si se libera lugar te aviso por acá. Si querés, decime otro horario y lo miro.";
        return ['reserva' => $r, 'respuesta' => $txt];
    }
}
