<?php

namespace App\Services\IA;

use App\Models\Alerta;
use App\Models\BigcitoRutina;
use App\Models\IaPropuesta;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Fase 119.4: rutinas de Bigcito. El usuario le encarga algo en palabras («todos los lunes a las 8 pasame cómo cerró la semana»,
// «todos los días a las 18 avisame si alguien pasó su límite») y Bigcito lo hace solo, con los permisos de esa persona:
// consulta, arma el informe y lo manda por la campana y el celular, por WhatsApp o por mail. Si hace falta hacer algo,
// lo deja propuesto para confirmar (por WhatsApp se confirma respondiendo SÍ).
class RutinasService
{
    public const FRECUENCIAS = ['cada_hora' => 'Cada hora', 'diaria' => 'Todos los días', 'habiles' => 'De lunes a viernes', 'semanal' => 'Por semana', 'mensual' => 'Una vez por mes'];
    public const CANALES = ['aviso' => 'Campana y celular', 'whatsapp' => 'WhatsApp', 'mail' => 'Mail'];
    public const DIAS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
    public const MAX_POR_USUARIO = 20;

    // Valida y normaliza lo que llega del formulario o de la herramienta. Lanza ValidationException con el motivo en castellano.
    public function normalizar(User $u, array $d): array
    {
        $instr = trim((string) ($d['instruccion'] ?? ''));
        if (mb_strlen($instr) < 5) throw ValidationException::withMessages(['instruccion' => 'Decime qué tiene que hacer la rutina (por ejemplo: «pasame cuánto vendí y cuánto cobré»).']);
        $frec = (string) ($d['frecuencia'] ?? 'diaria');
        if (! isset(self::FRECUENCIAS[$frec])) throw ValidationException::withMessages(['frecuencia' => 'La frecuencia tiene que ser: cada hora, todos los días, de lunes a viernes, por semana o una vez por mes.']);
        $hora = self::hora($d['hora'] ?? '08:00');
        if (! $hora) throw ValidationException::withMessages(['hora' => 'La hora va como 08:00 o 18:30.']);
        $dias = null; $diaMes = null;
        if ($frec === 'semanal') {
            $dias = self::dias($d['dias'] ?? []);
            if (! $dias) throw ValidationException::withMessages(['dias' => 'Decime qué día o días de la semana (lunes, martes…).']);
        }
        if ($frec === 'mensual') {
            $x = $d['dia_mes'] ?? 1; $diaMes = in_array($x, ['ultimo', 'último', 31, '31'], true) ? 31 : max(1, min(28, (int) $x));
        }
        $canal = (string) ($d['canal'] ?? '') ?: $this->canalPorDefecto($u);
        if (! isset(self::CANALES[$canal])) throw ValidationException::withMessages(['canal' => 'El aviso puede llegar por la campana y el celular, por WhatsApp o por mail.']);
        if ($canal === 'whatsapp' && ! $this->puedeWhatsapp($u)) throw ValidationException::withMessages(['canal' => 'Para que te llegue por WhatsApp la empresa tiene que tener WhatsApp conectado (Configuración → Tienda y canales) y vos tu celular cargado en Configuración → Usuarios.']);
        $nombre = trim((string) ($d['nombre'] ?? '')) ?: Str::limit(Str::ucfirst($instr), 60, '…');
        return ['nombre' => mb_substr($nombre, 0, 120), 'instruccion' => mb_substr($instr, 0, 2000), 'frecuencia' => $frec, 'dias' => $dias, 'dia_mes' => $diaMes, 'hora' => $hora, 'canal' => $canal, 'solo_si_hay' => (bool) ($d['solo_si_hay'] ?? false)];
    }

    public function crear(User $u, array $d): BigcitoRutina
    {
        $n = $this->normalizar($u, $d);
        if (BigcitoRutina::where('user_id', $u->id)->count() >= self::MAX_POR_USUARIO) throw ValidationException::withMessages(['rutina' => 'Ya tenés ' . self::MAX_POR_USUARIO . ' rutinas. Borrá alguna antes de sumar otra.']);
        $r = new BigcitoRutina($n + ['business_id' => $u->business_id, 'user_id' => $u->id, 'activa' => true]);
        $r->proxima_en = self::proxima($r);
        $r->save();
        return $r;
    }

    public function actualizar(BigcitoRutina $r, User $u, array $d): BigcitoRutina
    {
        $n = $this->normalizar($u, $d + ['nombre' => $r->nombre, 'instruccion' => $r->instruccion, 'frecuencia' => $r->frecuencia, 'dias' => $r->dias, 'dia_mes' => $r->dia_mes, 'hora' => $r->hora, 'canal' => $r->canal, 'solo_si_hay' => $r->solo_si_hay]);
        $r->fill($n); if (array_key_exists('activa', $d)) $r->activa = (bool) $d['activa'];
        $r->proxima_en = $r->activa ? self::proxima($r) : null;
        $r->save();
        return $r;
    }

    public static function hora($h): ?string
    {
        $h = trim(mb_strtolower((string) $h)); $pm = (bool) preg_match('/p\.?\s?m|tarde|noche/', $h); $am = (bool) preg_match('/a\.?\s?m|mañana/', $h);
        if (! preg_match('/(\d{1,2})(?:[:.h\s]+(\d{2}))?/', $h, $m)) return null;
        $hh = (int) $m[1]; $mm = isset($m[2]) ? (int) $m[2] : 0;
        if ($pm && $hh < 12) $hh += 12; if ($am && $hh === 12) $hh = 0;
        return $hh <= 23 && $mm <= 59 ? sprintf('%02d:%02d', $hh, $mm) : null;
    }

    // Días de la semana a números ISO (1 = lunes). Acepta números, nombres y «lunes a viernes».
    public static function dias($dias): array
    {
        $out = [];
        foreach ((array) (is_string($dias) ? preg_split('/[\s,;y]+/u', $dias) : $dias) as $d) {
            if (is_numeric($d)) { $n = (int) $d; if ($n >= 1 && $n <= 7) $out[] = $n; continue; }
            $t = Str::lower(Str::ascii(trim((string) $d)));
            foreach (self::DIAS as $n => $nombre) if ($t !== '' && str_starts_with(Str::ascii($nombre), substr($t, 0, 3)) && strlen($t) >= 2) { $out[] = $n; break; }
        }
        $out = array_values(array_unique($out)); sort($out);
        return $out;
    }

    // Cuándo le toca la próxima vez, contando desde $desde (por defecto, ahora).
    public static function proxima(BigcitoRutina $r, ?Carbon $desde = null): Carbon
    {
        $desde = ($desde ?? now())->copy(); [$hh, $mm] = array_map('intval', explode(':', $r->hora ?: '08:00'));
        if ($r->frecuencia === 'cada_hora') { $x = $desde->copy()->startOfHour()->addMinutes($mm); return $x->gt($desde) ? $x : $x->addHour(); }
        $x = $desde->copy()->setTime($hh, $mm, 0);
        for ($i = 0; $i < 400; $i++) {
            $ok = $x->gt($desde) && match ($r->frecuencia) {
                'habiles' => $x->isoWeekday() <= 5,
                'semanal' => in_array($x->isoWeekday(), (array) ($r->dias ?: [1]), true),
                'mensual' => (int) $r->dia_mes === 31 ? $x->day === $x->daysInMonth : $x->day === max(1, (int) ($r->dia_mes ?: 1)),
                default => true,
            };
            if ($ok) return $x;
            $x->addDay();
        }
        return $desde->copy()->addDay();
    }

    // «Todos los lunes y jueves a las 08:00, por WhatsApp».
    public function describir(BigcitoRutina $r): string
    {
        $cuando = match ($r->frecuencia) {
            'cada_hora' => 'Cada hora, al minuto ' . substr($r->hora, 3),
            'habiles' => "De lunes a viernes a las {$r->hora}",
            'semanal' => 'Todos los ' . collect((array) $r->dias)->map(fn($d) => self::DIAS[$d] ?? $d)->join(', ', ' y ') . " a las {$r->hora}",
            'mensual' => ((int) $r->dia_mes === 31 ? 'El último día de cada mes' : "El día {$r->dia_mes} de cada mes") . " a las {$r->hora}",
            default => "Todos los días a las {$r->hora}",
        };
        return $cuando . ', por ' . Str::lower(self::CANALES[$r->canal] ?? $r->canal) . ($r->solo_si_hay ? ' (sólo si hay algo para avisar)' : '');
    }

    public function puedeWhatsapp(User $u): bool
    {
        $ws = $u->business?->whatsapp_settings ?? [];
        return ! empty($ws['token']) && ! empty($ws['phone_id']) && strlen(preg_replace('/\D/', '', (string) $u->mobile)) >= 8;
    }

    public function canalPorDefecto(User $u): string { return $this->puedeWhatsapp($u) ? 'whatsapp' : 'aviso'; }

    // Corre todas las que ya tenían que salir. La usa el programador cada 5 minutos.
    public function correrPendientes(?Carbon $ahora = null): array
    {
        $ahora = $ahora ?? now(); $out = ['corridas' => 0, 'entregadas' => 0, 'sin_novedad' => 0, 'errores' => 0];
        $base = [config('services.anthropic.api_key'), config('services.anthropic.model')];
        $ids = BigcitoRutina::withoutGlobalScopes()->where('activa', true)->whereNotNull('proxima_en')->where('proxima_en', '<=', $ahora)->orderBy('proxima_en')->limit(200)->pluck('id');
        foreach ($ids as $id) {
            config(['services.anthropic.api_key' => $base[0], 'services.anthropic.model' => $base[1]]); // la clave de una empresa no pasa a la siguiente
            $r = BigcitoRutina::withoutGlobalScopes()->find($id); if (! $r) continue;
            $res = $this->ejecutar($r, true, $ahora);
            $out['corridas']++; $out[$res['estado'] === 'entregada' ? 'entregadas' : ($res['estado'] === 'sin_novedad' ? 'sin_novedad' : 'errores')]++;
        }
        config(['services.anthropic.api_key' => $base[0], 'services.anthropic.model' => $base[1]]);
        return $out;
    }

    // Hace la rutina ahora. Con $entregar manda el resultado por su canal y agenda la próxima; sin entregar sólo devuelve el texto (probar).
    public function ejecutar(BigcitoRutina $r, bool $entregar = true, ?Carbon $ahora = null): array
    {
        $ahora = $ahora ?? now();
        $u = User::withoutGlobalScopes()->find($r->user_id); $b = $u?->business;
        if (! $u || $u->status !== 'active' || ! $b || ! $b->is_active || $b->suspended_at) {
            if ($entregar) $r->forceFill(['activa' => false, 'proxima_en' => null, 'ultimo_resultado' => 'Se apagó sola: el usuario o la empresa ya no están activos.'])->save();
            return ['estado' => 'error', 'texto' => 'Usuario o empresa inactivos.', 'propuestas' => []];
        }
        $prev = Auth::user(); Auth::setUser($u);
        try {
            if (\App\Support\Ia::tiene($b)) \App\Support\Ia::aplicar($b);
            $extra = "Esto es una rutina programada que el usuario dejó encargada («{$r->nombre}»): no hay nadie mirando ahora. Hacé las consultas que hagan falta y respondé con el informe, corto, en texto plano, sin markdown, sin preguntas y sin la línea de SUGERENCIAS. No abras pantallas. Si corresponde hacer algo (cobrar, mandar recordatorios, pedir mercadería), llamá a las herramientas de acción: quedan como propuestas para que el usuario las confirme después."
                . ($r->solo_si_hay ? ' Es una rutina de vigilancia: si no hay nada para avisar, respondé exactamente NADA y nada más.' : '');
            $resp = app(BigcitoService::class)->responder($u, ['mensaje' => $r->instruccion, 'origen' => 'rutina', 'pantalla' => 'Rutina programada', 'extra_sistema' => $extra]);
            $texto = trim((string) ($resp['respuesta'] ?? ''));
            $props = $resp['propuestas'] ?? (! empty($resp['propuesta']) ? [$resp['propuesta']] : []);
            $nada = $r->solo_si_hay && ! $props && ($texto === '' || preg_match('/^\W*nada\W*$/iu', $texto));
            if (! $entregar) return ['estado' => $nada ? 'sin_novedad' : 'entregada', 'texto' => $nada ? 'Sin novedades: no hay nada para avisar.' : $texto, 'propuestas' => $props];

            if ($props) IaPropuesta::whereIn('id', array_column($props, 'id'))->update(['expira_en' => $ahora->copy()->addHours(24)]); // dan tiempo a verlas
            $canal = null;
            if (! $nada) $canal = $this->entregar($r, $u, $texto, $props);
            $r->forceFill(['ultima_en' => $ahora, 'ultimo_resultado' => $nada ? 'Sin novedades.' : mb_substr($texto, 0, 4000), 'propuestas' => $props ? array_map(fn($p) => ['id' => $p['id'], 'titulo' => $p['titulo'], 'detalle' => $p['detalle'] ?? null], $props) : null,
                'ejecuciones' => $r->ejecuciones + 1, 'proxima_en' => self::proxima($r, $ahora)])->save();
            return ['estado' => $nada ? 'sin_novedad' : 'entregada', 'texto' => $texto, 'propuestas' => $props, 'canal' => $canal];
        } catch (\Throwable $e) {
            report($e); Log::warning('Rutina de Bigcito: falló', ['rutina' => $r->id, 'e' => $e->getMessage()]);
            if ($entregar) $r->forceFill(['ultima_en' => $ahora, 'ultimo_resultado' => 'No se pudo hacer: ' . mb_substr($e->getMessage(), 0, 300), 'proxima_en' => self::proxima($r, $ahora)])->save();
            return ['estado' => 'error', 'texto' => $e->getMessage(), 'propuestas' => []];
        } finally {
            if ($prev) Auth::setUser($prev); elseif (method_exists(Auth::guard(), 'forgetUser')) Auth::guard()->forgetUser();
        }
    }

    // Manda el resultado por el canal de la rutina; si ese canal no está disponible, cae a la campana. Devuelve por dónde salió.
    private function entregar(BigcitoRutina $r, User $u, string $texto, array $props): string
    {
        $lista = $props ? "\n\n" . collect($props)->map(fn($p) => "➡️ {$p['titulo']}")->implode("\n") : '';
        if ($r->canal === 'whatsapp' && $this->puedeWhatsapp($u)) {
            $msg = "🤖 *{$r->nombre}*\n{$texto}" . ($props ? $lista . "\n\nRespondé SÍ para " . (count($props) > 1 ? 'hacerlas' : 'hacerlo') . ' o NO para cancelar.' : '');
            // Fase 120.1: si el usuario no le escribió al número en el último día, sale la plantilla «informe listo» y el informe le llega al contestar VER.
            $env = app(\App\Services\Canales\WhatsappPedidosService::class)->responder($u->business, (string) $u->mobile, $msg, ['nombre' => explode(' ', trim((string) $u->name))[0], 'plantilla' => 'bigsys_informe', 'params' => [explode(' ', trim((string) $u->name))[0], $r->nombre, $u->business->name]]);
            if (empty($env['error'])) {
                // Deja la charla de WhatsApp lista para que un SÍ confirme lo propuesto.
                $clave = "bigcito:wa:{$u->id}"; $estado = Cache::get($clave, ['historial' => [], 'propuestas' => []]);
                $estado['historial'][] = ['rol' => 'assistant', 'texto' => mb_substr($msg, 0, 1500)];
                $estado['propuestas'] = array_map(fn($p) => ['id' => $p['id'], 'titulo' => $p['titulo']], $props); unset($estado['propuesta']);
                Cache::put($clave, $estado, now()->addMinutes(BigcitoWhatsappService::TTL_MINUTOS));
                return 'whatsapp';
            }
        }
        if ($r->canal === 'mail' && $u->email) {
            try {
                Mail::raw($texto . ($props ? $lista . "\n\nPara confirmarlas entrá a " . url('/rutinas') : '') . "\n\n— Bigcito · " . $u->business->name . "\n" . url('/rutinas'), fn($m) => $m->to($u->email)->subject("Bigcito · {$r->nombre}"));
                return 'mail';
            } catch (\Throwable $e) { report($e); }
        }
        Alerta::emitir(['business_id' => $u->business_id, 'modulo' => 'dashboard', 'tipo' => 'rutina', 'severidad' => 'info', 'titulo' => "Bigcito · {$r->nombre}",
            'detalle' => Str::limit($texto, 380) . ($props ? ' · ' . count($props) . ' para confirmar' : ''), 'url' => '/rutinas', 'modelo' => 'rutina', 'modelo_id' => $r->id, 'leida_por' => null]);
        try { app(\App\Services\Avisos\CelularService::class)->enviar($u, "Bigcito · {$r->nombre}", Str::limit($texto, 160), '/rutinas'); } catch (\Throwable $e) { report($e); }
        try { app(\App\Services\Avisos\PushNativoService::class)->enviar($u, "Bigcito · {$r->nombre}", Str::limit($texto, 160), '/rutinas'); } catch (\Throwable $e) { report($e); }
        return 'aviso';
    }

    // Lo que muestra la pantalla y la herramienta mis_rutinas.
    public function resumir(BigcitoRutina $r): array
    {
        $pend = $r->propuestas ? IaPropuesta::whereIn('id', array_column($r->propuestas, 'id'))->where('estado', 'pendiente')->where('expira_en', '>', now())->pluck('id')->all() : [];
        return ['id' => $r->id, 'nombre' => $r->nombre, 'instruccion' => $r->instruccion, 'frecuencia' => $r->frecuencia, 'dias' => $r->dias ?? [], 'dia_mes' => $r->dia_mes, 'hora' => $r->hora, 'canal' => $r->canal, 'solo_si_hay' => $r->solo_si_hay, 'activa' => $r->activa,
            'cuando' => $this->describir($r), 'proxima' => $r->activa ? $r->proxima_en?->format('d/m H:i') : null, 'ultima' => $r->ultima_en?->format('d/m H:i'), 'ultimo_resultado' => $r->ultimo_resultado, 'ejecuciones' => $r->ejecuciones,
            'propuestas' => array_values(array_filter((array) $r->propuestas, fn($p) => in_array($p['id'], $pend, true)))];
    }
}
