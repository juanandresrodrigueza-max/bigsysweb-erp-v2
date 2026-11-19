<?php

namespace App\Services\Ventas;

use App\Models\AcuerdoPago;
use App\Models\Alerta;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\PlanPago;
use App\Models\User;
use App\Services\Canales\AtencionClientesService;
use App\Services\Canales\WhatsappPedidosService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

// Fase 120.5: Bigcito cobrador. Cuando un cliente con deuda vencida escribe por WhatsApp que no puede pagar todo junto (o
// responde PLAN a un recordatorio), Bigcito le ofrece un plan en cuotas DENTRO de los límites que fijó la empresa: cuántas
// cuotas como máximo, con qué interés, cuota mínima y cuándo vence la primera. Las cuentas las hace el sistema, no la IA:
// nunca ofrece algo que la empresa no autorizó. Lo que el cliente acepta queda como acuerdo para aprobar (o se aprueba solo,
// si la empresa lo eligió); recién ahí se arma el plan de pago de siempre. Lo que pide de más se le pasa a una persona.
class CobradorService
{
    public const DEFAULT = ['activo' => false, 'max_cuotas' => 3, 'interes_pct' => 0, 'cuota_minima' => 0, 'dias_primer_vto' => 10, 'aprobar' => true];
    private const HORAS = 24; // lo que dura la charla abierta (la ventana de WhatsApp)
    private const NUMEROS = ['un' => 1, 'una' => 1, 'uno' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10, 'once' => 11, 'doce' => 12, 'dieciocho' => 18, 'veinticuatro' => 24];

    public function __construct(private AtencionClientesService $atencion, private CobranzasService $cobranzas, private WhatsappPedidosService $wa) {}

    public static function config(Business $b): array
    {
        $c = array_replace(self::DEFAULT, (array) (($b->recordatorios ?? [])['cobrador'] ?? []));
        $c['max_cuotas'] = max(1, min(24, (int) $c['max_cuotas'])); $c['interes_pct'] = max(0, (float) $c['interes_pct']); $c['cuota_minima'] = max(0, (float) $c['cuota_minima']); $c['dias_primer_vto'] = max(0, min(60, (int) $c['dias_primer_vto']));
        $c['activo'] = (bool) $c['activo']; $c['aprobar'] = (bool) $c['aprobar'];
        return $c;
    }

    public static function activo(Business $b): bool { return self::config($b)['activo']; }

    // La frase que se agrega a los recordatorios por WhatsApp de facturas vencidas.
    public static function invitacion(Business $b): ?string
    {
        return self::activo($b) ? 'Si no podés pagar todo junto, respondé PLAN y lo armamos por acá.' : null;
    }

    private static function norm(string $t): string { return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($t)))); }

    // ¿El mensaje habla de pagar de a poco? (Con cuidado: «¿tienen cuotas con tarjeta?» es una consulta de compra, no un plan.)
    public static function pidePlan(string $texto): bool
    {
        $t = self::norm($texto);
        if (preg_match('/\b(tarjeta|comprar|precio|cuanto sale|cuanto cuesta|credito|ahora 12|presupuesto)\b/', $t)) return false;
        return (bool) preg_match('/\b(plan|planes|cuotas?|refinanci\w*|financia\w*|de a poco|en partes|en (dos|tres|2|3) (veces|pagos)|no (llego|puedo pagar|me alcanza|tengo la plata|tengo plata)|facilidad(es)?|dame tiempo|me das tiempo|un arreglo|arreglar la deuda|ponerme al dia)\b/', $t);
    }

    // La deuda vencida del cliente y lo que se le puede ofrecer con los límites de la empresa.
    public function oferta(Business $b, Contact $c): ?array
    {
        $cfg = self::config($b);
        $vencidas = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('contact_id', $c->id)->ventas()->pendientesCobro()->whereNotNull('fecha_vto')->where('fecha_vto', '<', today())->orderBy('fecha_vto')->get();
        $deuda = round((float) $vencidas->sum(fn($f) => (float) $f->saldo), 2);
        if ($deuda <= 0.005) return null;
        $total = round($deuda * (1 + $cfg['interes_pct'] / 100), 2);
        $max = $cfg['max_cuotas'];
        if ($cfg['cuota_minima'] > 0) $max = max(1, min($max, (int) floor($total / $cfg['cuota_minima'])));
        $primera = today()->addDays($cfg['dias_primer_vto']);
        return ['deuda' => $deuda, 'facturas' => $vencidas->count(), 'comprobantes' => $vencidas->pluck('id')->all(), 'interes_pct' => $cfg['interes_pct'], 'total' => $total, 'max' => $max, 'primer_vencimiento' => $primera->toDateString()];
    }

    private static function cuotaDe(array $o, int $n): float { return round($o['total'] / $n, 2); }

    private static function pesos(float $v): string { return '$ ' . number_format($v, 0, ',', '.'); }

    private static function clave(Business $b, string $tel): string { return 'cobrador:' . $b->id . ':' . substr(preg_replace('/\D/', '', $tel), -10); }

    // Atiende el mensaje si es parte de una negociación de pago. Devuelve ['respuesta' => …] o null (que siga el camino de siempre).
    public function atender(Business $b, ?string $tel, string $texto): ?array
    {
        if (! $tel || trim($texto) === '' || ! self::activo($b)) return null;
        $clave = self::clave($b, $tel);
        $st = Cache::get($clave);
        $c = $st ? Contact::withoutGlobalScopes()->where('business_id', $b->id)->find($st['contact_id']) : $this->atencion->cliente($b, $tel, $texto);
        if (! $c) return null;
        $t = self::norm($texto);
        $nombre = Str::of($c->name)->explode(' ')->first();

        if (! $st) {
            if (! self::pidePlan($texto)) return null;
            $pend = AcuerdoPago::withoutGlobalScopes()->where('business_id', $b->id)->where('contact_id', $c->id)->where('estado', 'pendiente')->latest('id')->first();
            if ($pend) return ['respuesta' => "Hola {$nombre}. Tu propuesta de {$pend->cuotas} " . ($pend->cuotas === 1 ? 'pago' : 'cuotas') . ' de ' . self::pesos($pend->cuota) . " ya está anotada. En cuanto {$b->name} la apruebe te confirmo por acá."];
            $o = $this->oferta($b, $c);
            if (! $o) return null; // sin nada vencido no hay qué refinanciar: que conteste el camino de siempre (saldo, pedido…)
            Cache::put($clave, ['paso' => 'elegir', 'contact_id' => $c->id, 'oferta' => $o, 'intentos' => 0, 'charla' => [['c', mb_substr($texto, 0, 300)]]], now()->addHours(self::HORAS));
            return $this->decir($clave, $this->textoOferta($b, $nombre, $o));
        }

        $st['charla'][] = ['c', mb_substr($texto, 0, 300)];
        $o = $st['oferta'];

        // Pide hablar con alguien, o dice que no puede con nada: se le pasa a una persona.
        if (preg_match('/\b(hablar con|una persona|un humano|que me llamen|llamame|no puedo (pagar )?(nada|ninguna)|imposible|ninguna de esas|ninguna me sirve)\b/', $t)) {
            $this->avisar($b, $c, "{$c->name} pide hablar con alguien por su deuda", 'No le sirvió lo que Bigcito puede ofrecer (hasta ' . $o['max'] . ' cuotas). Dijo: «' . mb_substr($texto, 0, 160) . '»');
            Cache::forget($clave);
            return ['respuesta' => "Entiendo, {$nombre}. Ya le avisé a {$b->name} para que te contacten y lo vean con vos."];
        }

        if ($st['paso'] === 'confirmar') {
            if (preg_match('/^\W*(si|sii+|dale|de una|ok|okey|listo|confirmo|confirmado|acepto|perfecto|esta bien|bueno|va)\b/', $t)) {
                $a = $this->anotar($b, $c, $tel, $o, (int) $st['cuotas'], $st['charla']);
                Cache::forget($clave);
                if (! self::config($b)['aprobar']) {
                    $r = $this->aprobar($a, null, false);
                    if ($r['ok']) return ['respuesta' => "Listo, {$nombre}: quedó confirmado tu plan {$r['plan']}.\n" . $this->detalle($o, $a->cuotas) . "\nGracias por ponerte al día."];
                }
                return ['respuesta' => "Anotado, {$nombre}. En cuanto {$b->name} lo apruebe te confirmo por acá. Gracias por ponerte al día."];
            }
            if (preg_match('/^\W*(no\b|nop\b|mejor no|para\b|cambi)/', $t) && self::cuotasEn($t) === null) {
                $st['paso'] = 'elegir'; Cache::put($clave, $st, now()->addHours(self::HORAS));
                return $this->decir($clave, $o['max'] > 1 ? "Dale, lo cambiamos. ¿En cuántas cuotas preferís? De 1 a {$o['max']}." : 'Dale. Si necesitás otra cosa decime y le aviso a ' . $b->name . '.');
            }
            // Si en vez de sí o no dice otro número, sigue como elección.
        }

        $n = self::cuotasEn($t);
        if ($n === null && $o['max'] === 1 && preg_match('/^\W*(si|dale|ok|okey|listo|acepto|perfecto|bueno)\b/', $t)) $n = 1;
        if ($n !== null) {
            if ($n > $o['max']) {
                $this->avisar($b, $c, "{$c->name} pide {$n} cuotas", "El tope que configuraste es {$o['max']}. Debe " . self::pesos($o['deuda']) . ' vencidos. Si querés dárselas, armá el plan desde Cobranzas.');
                $st['intentos'] = 0; Cache::put($clave, $st, now()->addHours(self::HORAS));
                return $this->decir($clave, "Lo máximo que puedo armarte yo son {$o['max']} " . ($o['max'] === 1 ? 'pago' : 'cuotas') . ". Ya le avisé a {$b->name} que pedís {$n}: te van a contestar. Si mientras tanto te sirve " . ($o['max'] === 1 ? 'así' : "alguna de 1 a {$o['max']}") . ', decime.');
            }
            $st['paso'] = 'confirmar'; $st['cuotas'] = $n; $st['intentos'] = 0; Cache::put($clave, $st, now()->addHours(self::HORAS));
            return $this->decir($clave, "Perfecto. Quedaría así:\n" . $this->detalle($o, $n) . "\n¿Lo dejamos así? Respondé SÍ para confirmarlo o NO para cambiarlo.");
        }

        // No se entendió: una repregunta, y si sigue sin entenderse, que lo atienda el camino de siempre.
        if (($st['intentos'] ?? 0) >= 1) { Cache::forget($clave); return null; }
        $st['intentos'] = ($st['intentos'] ?? 0) + 1; Cache::put($clave, $st, now()->addHours(self::HORAS));
        return $this->decir($clave, $st['paso'] === 'confirmar' ? 'No te entendí. Respondé SÍ para confirmar el plan o NO para cambiarlo.' : "No te entendí. Decime en cuántas cuotas querés pagarlo, de 1 a {$o['max']} (por ejemplo: 2).");
    }

    // Cuántas cuotas dijo: «3», «en tres», «dos cuotas», «un pago», «todo junto». Un número suelto en medio de otra frase
    // («te pago el 20», «mandame 10 bolsas») no cuenta: tiene que venir solo, con «en» adelante o con «cuotas/pagos/veces» atrás.
    private static function cuotasEn(string $t): ?int
    {
        if (preg_match('/\b(todo junto|de una sola vez|un solo pago|un pago|una cuota|al contado)\b/', $t)) return 1;
        if (preg_match('/^\W*(en |las |los |de a |serian |prefiero )?(\d{1,2})\W*$/', $t, $m)) return max(1, (int) $m[2]);
        if (preg_match('/\b(\d{1,2}) ?(cuotas?|pagos?|veces|meses)\b/', $t, $m)) return max(1, (int) $m[1]);
        if (preg_match('/\ben (\d{1,2})\b(?! ?(dias?|de |\/|hs|horas?))/', $t, $m)) return max(1, (int) $m[1]);
        foreach (self::NUMEROS as $palabra => $n) {
            if (in_array($palabra, ['un', 'una', 'uno'], true)) continue;
            if (preg_match('/\b' . $palabra . '\b(?! ?(dias?|semanas?|horas?|bolsas?|unidades?))/', $t)) return $n;
        }
        return null;
    }

    private function textoOferta(Business $b, string $nombre, array $o): string
    {
        $int = $o['interes_pct'] > 0 ? ' con ' . rtrim(rtrim(number_format($o['interes_pct'], 1, ',', ''), '0'), ',') . ' % de interés' : ' sin interés';
        $venc = \Carbon\Carbon::parse($o['primer_vencimiento'])->format('d/m');
        $cab = "Hola {$nombre}. Tenés " . self::pesos($o['deuda']) . ' vencidos con ' . $b->name . ($o['facturas'] > 1 ? " ({$o['facturas']} facturas)" : '') . '.';
        if ($o['max'] === 1) return $cab . ' Te puedo dar hasta el ' . $venc . ' para pagar ' . self::pesos($o['total']) . ($o['interes_pct'] > 0 ? $int : '') . ". ¿Te comprometés a esa fecha? Respondé SÍ.";
        $lineas = collect(range(1, $o['max']))->map(fn($n) => $n === 1 ? '• 1 pago de ' . self::pesos($o['total']) : "• {$n} cuotas de " . self::pesos(self::cuotaDe($o, $n)))->implode("\n");
        return $cab . " Para ponerte al día te puedo ofrecer hasta {$o['max']} cuotas mensuales{$int}:\n{$lineas}\nLa primera vence el {$venc}. ¿En cuántas te queda cómodo? Respondé con el número.";
    }

    private function detalle(array $o, int $n): string
    {
        $primera = \Carbon\Carbon::parse($o['primer_vencimiento']); $cuota = self::cuotaDe($o, $n); $acum = 0; $l = [];
        for ($i = 1; $i <= $n; $i++) { $monto = $i === $n ? round($o['total'] - $acum, 2) : $cuota; $acum += $monto; $l[] = ($n === 1 ? '• Un pago de ' : "• Cuota {$i}: ") . self::pesos($monto) . ' el ' . $primera->copy()->addMonths($i - 1)->format('d/m'); }
        return implode("\n", $l) . ($n > 1 ? "\nTotal: " . self::pesos($o['total']) : '');
    }

    private function decir(string $clave, string $texto): array
    {
        if ($st = Cache::get($clave)) { $st['charla'][] = ['b', mb_substr($texto, 0, 600)]; Cache::put($clave, $st, now()->addHours(self::HORAS)); }
        return ['respuesta' => $texto];
    }

    private function avisar(Business $b, Contact $c, string $titulo, string $detalle): void
    {
        Alerta::create(['business_id' => $b->id, 'modulo' => 'clientes', 'tipo' => 'cobrador_pedido', 'severidad' => 'aviso', 'modelo' => 'Contact', 'modelo_id' => $c->id, 'titulo' => mb_substr($titulo, 0, 150), 'detalle' => mb_substr($detalle, 0, 300), 'url' => '/clientes/cobranzas']);
    }

    private function anotar(Business $b, Contact $c, string $tel, array $o, int $n, array $charla): AcuerdoPago
    {
        $a = new AcuerdoPago(['business_id' => $b->id, 'contact_id' => $c->id, 'telefono' => mb_substr($tel, 0, 30), 'estado' => 'pendiente', 'deuda' => $o['deuda'], 'cuotas' => $n, 'interes_pct' => $o['interes_pct'], 'total' => $o['total'], 'cuota' => self::cuotaDe($o, $n), 'primer_vencimiento' => $o['primer_vencimiento'], 'comprobantes' => $o['comprobantes'], 'charla' => array_slice($charla, -20)]);
        $a->saveQuietly(); // sin eventos: en el aviso de WhatsApp no hay nadie logueado
        Alerta::create(['business_id' => $b->id, 'modulo' => 'clientes', 'tipo' => 'cobrador_acuerdo', 'severidad' => 'aviso', 'modelo' => 'AcuerdoPago', 'modelo_id' => $a->id, 'titulo' => "{$c->name} aceptó un plan de pago por WhatsApp",
            'detalle' => ($n === 1 ? 'Un pago de ' : "{$n} cuotas de ") . self::pesos($a->cuota) . ' (debe ' . self::pesos($o['deuda']) . ' vencidos). ' . (self::config($b)['aprobar'] ? 'Aprobalo en Cobranzas para que quede armado.' : 'Se aprobó solo, como configuraste.'), 'url' => '/clientes/cobranzas']);
        return $a;
    }

    // La empresa aprueba: se arma el plan de pago de siempre sobre las facturas que siguen pendientes y se le avisa al cliente.
    public function aprobar(AcuerdoPago $a, ?User $por = null, bool $avisarCliente = true): array
    {
        if ($a->estado !== 'pendiente') return ['ok' => false, 'error' => 'Ese acuerdo ya estaba resuelto.'];
        $b = Business::findOrFail($a->business_id);
        $u = $por ?: ($b->owner ?: User::withoutGlobalScopes()->where('business_id', $b->id)->where('status', 'active')->orderBy('id')->first());
        if (! $u) return ['ok' => false, 'error' => 'La empresa no tiene usuarios activos.'];
        $prev = Auth::user(); $cambio = ! $prev || $prev->id !== $u->id;
        if ($cambio) Auth::setUser($u);
        try {
            $c = Contact::findOrFail($a->contact_id);
            $ids = Comprobante::where('contact_id', $c->id)->pendientesCobro()->whereIn('id', $a->comprobantes ?? [])->pluck('id')->all();
            if (! $ids) { $a->update(['estado' => 'rechazado', 'motivo' => 'Las facturas ya no estaban pendientes.', 'resuelto_por' => $por?->id, 'resuelto_en' => now()]); return ['ok' => false, 'error' => 'Las facturas de ese acuerdo ya no están pendientes (se cobraron o se refinanciaron): quedó cerrado.']; }
            $primera = $a->primer_vencimiento->lt(today()) ? today()->addDays(3) : $a->primer_vencimiento;
            $plan = $this->cobranzas->refinanciar($c, ['comprobantes' => $ids, 'cuotas' => $a->cuotas, 'interes_pct' => $a->interes_pct, 'primer_vencimiento' => $primera->toDateString(), 'notas' => 'Acordado con el cliente por WhatsApp (Bigcito cobrador).']);
            $a->update(['estado' => 'aprobado', 'plan_pago_id' => $plan->id, 'resuelto_por' => $por?->id, 'resuelto_en' => now()]);
            Alerta::where('tipo', 'cobrador_acuerdo')->where('modelo_id', $a->id)->whereNull('resuelta_en')->update(['resuelta_en' => now()]);
            AuditLog::registrar('crear', $plan, "Acuerdo de pago por WhatsApp aprobado: {$c->name}, {$a->cuotas} cuota(s)" . ($por ? '' : ' (aprobación automática)'));
            if ($avisarCliente && $a->telefono) {
                $nombre = Str::of($c->name)->explode(' ')->first();
                $cuotas = $plan->cuotas()->get()->map(fn($q) => "• Cuota {$q->numero}: " . self::pesos((float) $q->monto) . ' el ' . \Carbon\Carbon::parse($q->vencimiento)->format('d/m'))->implode("\n");
                try { $this->wa->responder($b, $a->telefono, "Hola {$nombre}. {$b->name} aprobó tu plan de pago {$plan->numeroFormateado()}:\n{$cuotas}\nGracias por ponerte al día.", ['nombre' => $nombre]); } catch (\Throwable $e) { report($e); }
            }
            return ['ok' => true, 'plan' => $plan->numeroFormateado(), 'plan_id' => $plan->id, 'total' => (float) $plan->total];
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['ok' => false, 'error' => collect($e->errors())->flatten()->first() ?? 'No se pudo armar el plan.'];
        } finally {
            if ($cambio) { if ($prev) Auth::setUser($prev); elseif (method_exists(Auth::guard(), 'forgetUser')) Auth::guard()->forgetUser(); }
        }
    }

    public function rechazar(AcuerdoPago $a, ?User $por, ?string $motivo = null, bool $avisarCliente = true): array
    {
        if ($a->estado !== 'pendiente') return ['ok' => false, 'error' => 'Ese acuerdo ya estaba resuelto.'];
        $a->update(['estado' => 'rechazado', 'motivo' => $motivo ? mb_substr($motivo, 0, 200) : null, 'resuelto_por' => $por?->id, 'resuelto_en' => now()]);
        Alerta::where('tipo', 'cobrador_acuerdo')->where('modelo_id', $a->id)->whereNull('resuelta_en')->update(['resuelta_en' => now()]);
        if ($avisarCliente && $a->telefono) {
            $b = Business::findOrFail($a->business_id); $c = Contact::withoutGlobalScopes()->find($a->contact_id); $nombre = $c ? Str::of($c->name)->explode(' ')->first() : '';
            try { $this->wa->responder($b, $a->telefono, trim("Hola {$nombre}.") . " Por ahora {$b->name} no puede tomar ese plan de pago" . ($motivo ? ": {$motivo}" : '') . '. Te van a contactar para verlo.', ['nombre' => $nombre]); } catch (\Throwable $e) { report($e); }
        }
        return ['ok' => true];
    }

    // Para la pantalla de Cobranzas y para Bigcito.
    public function pendientes(Business $b): array
    {
        return AcuerdoPago::where('business_id', $b->id)->where('estado', 'pendiente')->with('contact:id,name')->orderBy('id')->limit(50)->get()->map(fn($a) => [
            'id' => $a->id, 'cliente' => $a->contact?->name, 'contact_id' => $a->contact_id, 'deuda' => $a->deuda, 'cuotas' => $a->cuotas, 'cuota' => $a->cuota, 'total' => $a->total, 'interes_pct' => $a->interes_pct,
            'primer_vencimiento' => $a->primer_vencimiento->format('d/m/Y'), 'cuando' => $a->created_at->diffForHumans(), 'charla' => $a->charla ?? [],
        ])->all();
    }
}
