<?php

namespace App\Services\Equipo;

use App\Models\Alerta;
use App\Models\Business;
use App\Models\Encargo;
use App\Models\User;
use App\Services\Canales\WhatsappPedidosService;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Fase 120.6: encargos entre personas del equipo. Uno le deja algo a otro («prepará el pedido 12 para las 17»): al que lo
// recibe le llega por WhatsApp (si tiene celular cargado y la empresa tiene el WhatsApp conectado) y por la campana; contesta
// HECHO y al que lo pidió le llega el aviso. Lo vencido se recuerda una vez por día. Se puede cargar desde la pantalla, por
// Bigcito («decile a Pedro que…») o por WhatsApp.
class EncargosService
{
    public const MAX_ABIERTOS = 200; // por empresa, para que no sirva de cola infinita

    public function __construct(private WhatsappPedidosService $wa) {}

    private static function nombre(?User $u): string { return $u ? (explode(' ', trim((string) $u->name))[0] ?: 'alguien') : 'alguien'; }

    // Las personas a las que este usuario les puede encargar algo: los usuarios activos de su empresa.
    public function equipo(User $u): \Illuminate\Support\Collection
    {
        return User::where('business_id', $u->business_id)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'mobile', 'email']);
    }

    // Busca a la persona por nombre («Pedro», «pedro gomez»). Devuelve el usuario, o null si no hay o hay más de uno.
    public function persona(User $u, string $nombre): ?User
    {
        $q = Str::lower(Str::ascii(trim($nombre))); if ($q === '') return null;
        if (in_array($q, ['mi', 'yo', 'mi mismo', 'a mi'], true)) return $u;
        $eq = $this->equipo($u);
        $norm = fn(User $x) => Str::lower(Str::ascii($x->name));
        $exacto = $eq->filter(fn($x) => $norm($x) === $q);
        if ($exacto->count() === 1) return $exacto->first();
        $c = $eq->filter(fn($x) => str_contains(' ' . $norm($x) . ' ', ' ' . $q . ' ') || str_starts_with($norm($x), $q));
        return $c->count() === 1 ? $c->first() : null;
    }

    public function crear(User $de, User $para, string $texto, ?Carbon $vence = null, ?string $detalle = null, ?string $url = null, string $origen = 'pantalla'): Encargo
    {
        $texto = trim(preg_replace('/\s+/', ' ', $texto));
        if ($texto === '') throw ValidationException::withMessages(['texto' => 'Decime qué hay que hacer.']);
        if ($para->business_id !== $de->business_id || $para->status !== 'active') throw ValidationException::withMessages(['para' => 'Esa persona no está en el equipo de la empresa.']);
        if ($vence && $vence->lt(now()->subMinutes(5))) throw ValidationException::withMessages(['vence_en' => 'La fecha para terminarlo ya pasó.']);
        if (Encargo::where('business_id', $de->business_id)->where('estado', 'pendiente')->count() >= self::MAX_ABIERTOS) throw ValidationException::withMessages(['texto' => 'Hay demasiados encargos abiertos. Cerrá o cancelá algunos antes de sumar más.']);
        $e = Encargo::create(['business_id' => $de->business_id, 'de_user_id' => $de->id, 'para_user_id' => $para->id, 'texto' => mb_substr($texto, 0, 300), 'detalle' => $detalle ? mb_substr(trim($detalle), 0, 2000) : null,
            'url' => $url && str_starts_with($url, '/') ? mb_substr($url, 0, 250) : null, 'vence_en' => $vence, 'estado' => 'pendiente', 'origen' => $origen]);
        if ($para->id !== $de->id) $e->update(['aviso' => $this->avisar($e)]);
        return $e;
    }

    // Le avisa al que tiene que hacerlo. Devuelve por dónde salió.
    private function avisar(Encargo $e, bool $recordatorio = false): string
    {
        $de = $e->de; $para = $e->para; $b = Business::find($e->business_id);
        $cuando = $e->vence_en ? ' Para ' . self::cuando($e->vence_en) . '.' : '';
        $titulo = $recordatorio ? 'Encargo vencido: ' . Str::limit($e->texto, 90) : self::nombre($de) . ' te dejó un encargo';
        Alerta::emitir(['business_id' => $e->business_id, 'user_id' => $para->id, 'modulo' => 'dashboard', 'tipo' => 'encargo', 'severidad' => $recordatorio ? 'aviso' : 'info', 'modelo' => 'Encargo', 'modelo_id' => $e->id,
            'titulo' => $titulo, 'detalle' => Str::limit($e->texto, 200) . $cuando, 'url' => '/encargos', 'leida_por' => null]);
        try { app(\App\Services\Avisos\CelularService::class)->enviar($para, $titulo, Str::limit($e->texto, 140) . $cuando, '/encargos'); } catch (\Throwable $x) { report($x); }
        try { app(\App\Services\Avisos\PushNativoService::class)->enviar($para, $titulo, Str::limit($e->texto, 140) . $cuando, '/encargos'); } catch (\Throwable $x) { report($x); }
        if ($b && $para->mobile && ! empty($b->whatsapp_settings['token']) && ! empty($b->whatsapp_settings['phone_id'])) {
            $msg = ($recordatorio ? "⏰ Sigue pendiente el encargo de " . self::nombre($de) . ':' : '📌 ' . self::nombre($de) . ' te dejó un encargo:') . "\n*{$e->texto}*" . ($e->detalle ? "\n{$e->detalle}" : '') . ($cuando ? "\n" . trim($cuando) : '')
                . "\n\nRespondé HECHO cuando lo termines.";
            try {
                // Fuera de las 24 horas sale la plantilla «encargo»; el texto completo le llega cuando contesta.
                $r = $this->wa->responder($b, (string) $para->mobile, $msg, ['nombre' => self::nombre($para), 'plantilla' => 'bigsys_encargo', 'params' => [self::nombre($para), self::nombre($de), $b->name, Str::limit($e->texto . ($cuando ? ' (' . trim($cuando, ' .') . ')' : ''), 180)]]);
                if (! empty($r['enviado'])) return 'whatsapp';
            } catch (\Throwable $x) { report($x); }
        }
        return 'campana';
    }

    public static function cuando(Carbon $f): string
    {
        $hora = $f->format('H:i') === '23:59' ? '' : ' a las ' . $f->format('H:i');
        if ($f->isToday()) return 'hoy' . $hora;
        if ($f->isTomorrow()) return 'mañana' . $hora;
        return 'el ' . $f->format('d/m') . $hora;
    }

    // Quien lo tenía (o quien lo pidió) lo da por hecho. Le avisa al que lo encargó.
    public function hecho(Encargo $e, User $por, ?string $nota = null): Encargo
    {
        if ($e->estado !== 'pendiente') return $e;
        if (! in_array($por->id, [$e->para_user_id, $e->de_user_id], true)) throw ValidationException::withMessages(['encargo' => 'Ese encargo no es tuyo.']);
        $e->update(['estado' => 'hecho', 'hecho_en' => now(), 'nota' => $nota ? mb_substr(trim($nota), 0, 300) : null]);
        Alerta::where('tipo', 'encargo')->where('modelo_id', $e->id)->whereNull('resuelta_en')->update(['resuelta_en' => now()]);
        if ($e->de_user_id !== $por->id && ($de = $e->de)) {
            $titulo = self::nombre($por) . ' terminó lo que le encargaste';
            $det = Str::limit($e->texto, 160) . ($e->nota ? " · «{$e->nota}»" : '');
            Alerta::create(['business_id' => $e->business_id, 'user_id' => $de->id, 'modulo' => 'dashboard', 'tipo' => 'encargo_hecho', 'severidad' => 'info', 'modelo' => 'Encargo', 'modelo_id' => $e->id, 'titulo' => $titulo, 'detalle' => $det, 'url' => '/encargos']);
            try { app(\App\Services\Avisos\CelularService::class)->enviar($de, $titulo, $det, '/encargos'); } catch (\Throwable $x) { report($x); }
            try { app(\App\Services\Avisos\PushNativoService::class)->enviar($de, $titulo, $det, '/encargos'); } catch (\Throwable $x) { report($x); }
            $b = Business::find($e->business_id);
            if ($b && $de->mobile && ! empty($b->whatsapp_settings['token']) && ! empty($b->whatsapp_settings['phone_id'])) {
                try { $this->wa->responder($b, (string) $de->mobile, "✅ {$titulo}:\n*{$e->texto}*" . ($e->nota ? "\n«{$e->nota}»" : ''), ['nombre' => self::nombre($de)]); } catch (\Throwable $x) { report($x); }
            }
        }
        return $e;
    }

    public function cancelar(Encargo $e, User $por): Encargo
    {
        if ($e->estado !== 'pendiente') return $e;
        if ($por->id !== $e->de_user_id) throw ValidationException::withMessages(['encargo' => 'Sólo quien lo encargó puede cancelarlo.']);
        $e->update(['estado' => 'cancelado']);
        Alerta::where('tipo', 'encargo')->where('modelo_id', $e->id)->whereNull('resuelta_en')->update(['resuelta_en' => now()]);
        return $e;
    }

    public function reabrir(Encargo $e, User $por): Encargo
    {
        if ($e->estado === 'pendiente') return $e;
        if ($por->id !== $e->de_user_id) throw ValidationException::withMessages(['encargo' => 'Sólo quien lo encargó puede reabrirlo.']);
        $e->update(['estado' => 'pendiente', 'hecho_en' => null, 'nota' => null, 'recordado_en' => null]);
        if ($e->para_user_id !== $e->de_user_id) $e->update(['aviso' => $this->avisar($e)]);
        return $e;
    }

    // Lo que tengo que hacer yo, del más urgente al menos.
    public function paraMi(User $u): \Illuminate\Support\Collection
    {
        return Encargo::where('business_id', $u->business_id)->where('para_user_id', $u->id)->where('estado', 'pendiente')->with('de:id,name')->get()
            ->sortBy(fn($e) => [$e->vence_en ? 0 : 1, $e->vence_en?->timestamp ?? 0, $e->id])->values();
    }

    public function resumir(Encargo $e): array
    {
        return ['id' => $e->id, 'texto' => $e->texto, 'detalle' => $e->detalle, 'url' => $e->url, 'de' => $e->de?->name, 'para' => $e->para?->name, 'de_id' => $e->de_user_id, 'para_id' => $e->para_user_id,
            'vence' => $e->vence_en ? self::cuando($e->vence_en) : null, 'vence_iso' => $e->vence_en?->format('Y-m-d\TH:i'), 'vencido' => $e->vencido(), 'estado' => $e->estado, 'nota' => $e->nota, 'aviso' => $e->aviso,
            'hecho' => $e->hecho_en?->diffForHumans(), 'cuando' => $e->created_at->diffForHumans()];
    }

    // Una vez por día, lo vencido se le recuerda a quien lo tiene y se le cuenta a quien lo pidió.
    public function recordarVencidos(): int
    {
        $n = 0;
        $lista = Encargo::withoutGlobalScopes()->where('estado', 'pendiente')->whereNotNull('vence_en')->where('vence_en', '<', now())
            ->where(fn($w) => $w->whereNull('recordado_en')->orWhere('recordado_en', '<', now()->subHours(23)))->where('vence_en', '>', now()->subDays(14))->limit(300)->get();
        foreach ($lista as $e) {
            $para = User::withoutGlobalScopes()->find($e->para_user_id); $de = User::withoutGlobalScopes()->find($e->de_user_id);
            if (! $para || ! $de || $para->status !== 'active') { $e->forceFill(['recordado_en' => now()])->saveQuietly(); continue; }
            $e->setRelation('para', $para); $e->setRelation('de', $de);
            try {
                $this->avisar($e, true);
                if ($de->id !== $para->id) Alerta::emitir(['business_id' => $e->business_id, 'user_id' => $de->id, 'modulo' => 'dashboard', 'tipo' => 'encargo_atrasado', 'severidad' => 'aviso', 'modelo' => 'Encargo', 'modelo_id' => $e->id,
                    'titulo' => self::nombre($para) . ' todavía no hizo lo que le encargaste', 'detalle' => Str::limit($e->texto, 200) . ' · vencía ' . self::cuando($e->vence_en), 'url' => '/encargos', 'leida_por' => null]);
                $n++;
            } catch (\Throwable $x) { report($x); }
            $e->forceFill(['recordado_en' => now()])->saveQuietly();
        }
        return $n;
    }

    // Por WhatsApp, alguien del equipo contesta HECHO, VER o MIS ENCARGOS. Devuelve la respuesta, o null si el mensaje no era de esto.
    public function porWhatsapp(User $u, string $texto): ?string
    {
        $t = Str::lower(Str::ascii(trim($texto)));
        $esHecho = preg_match('/^\W*(hecho|hecha|terminado|terminada|ya esta|ya lo hice|ya la hice|cumplido)\b\W*(?:el |la |n |nro |numero |#)?(\d{1,3})?\W*(.*)$/', $t, $m);
        $esVer = preg_match('/^\W*(ver|mis encargos|encargos|que tengo pendiente|pendientes)\W*$/', $t);
        if (! $esHecho && ! $esVer) return null;
        $mios = $this->paraMi($u);
        if ($mios->isEmpty()) return $esVer && $t === 'ver' ? null : 'No tenés encargos pendientes. 👌';
        $lista = fn() => $mios->values()->map(fn($e, $i) => ($i + 1) . ') ' . $e->texto . ($e->vence_en ? ' · para ' . self::cuando($e->vence_en) . ($e->vencido() ? ' (vencido)' : '') : '') . ' · de ' . self::nombre($e->de))->implode("\n");
        if ($esVer) return "Tus encargos pendientes:\n" . $lista() . "\n\nRespondé HECHO" . ($mios->count() > 1 ? ' y el número (por ejemplo: HECHO 1).' : ' cuando lo termines.');
        $n = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null; $nota = trim((string) ($m[3] ?? ''));
        if ($n === null && $mios->count() > 1) return "Tenés {$mios->count()} encargos pendientes. ¿Cuál terminaste?\n" . $lista() . "\n\nRespondé HECHO y el número (por ejemplo: HECHO 1).";
        $e = $n === null ? $mios->first() : $mios->values()->get($n - 1);
        if (! $e) return "No encuentro el encargo {$n}. Tus pendientes:\n" . $lista();
        // La nota va con el texto original (con sus mayúsculas y acentos), no con el normalizado.
        $notaOriginal = $nota !== '' ? trim(mb_substr(trim($texto), mb_strlen(trim($texto)) - mb_strlen($nota))) : null;
        $this->hecho($e, $u, $notaOriginal);
        $quedan = $mios->count() - 1;
        return "✅ Listo, marqué como hecho: {$e->texto}." . ($e->de_user_id !== $u->id ? ' Le avisé a ' . self::nombre($e->de) . '.' : '') . ($quedan ? " Te quedan {$quedan}." : '');
    }
}
