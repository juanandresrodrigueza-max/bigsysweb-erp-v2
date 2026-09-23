<?php

namespace App\Services\Ventas;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\Envio;
use App\Models\PlanPago;
use App\Models\User;
use App\Services\Comprobantes\ComprobanteService;
use App\Services\Envios\EnvioService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Cobranzas: recordatorios automáticos por vencimiento, mora y refinanciación en cuotas.
class CobranzasService
{
    public const DEFAULT = ['activo' => false, 'dias' => [-3, 0, 7, 30], 'canales' => ['mail'], 'texto' => "Hola {cliente}, te recordamos que la {comprobante} por {importe} {estado}. Podés verla y pagarla acá: {link}. ¡Gracias!"];

    public function __construct(private EnvioService $envios, private ComprobanteService $comprobantes) {}

    public function config(Business $b): array { return array_replace(self::DEFAULT, $b->recordatorios ?? []); }

    // Deudores con detalle de vencido y mora calculada.
    public function deudores(): \Illuminate\Support\Collection
    {
        return Contact::customers()->where('balance', '>', 0.005)->with('vendedor:id,nombre')->orderByDesc('balance')->get()->map(function ($c) {
            $pend = Comprobante::where('contact_id', $c->id)->pendientesCobro()->get();
            $venc = $pend->filter(fn($p) => $p->vencido());
            $mora = 0; $diasMax = 0;
            foreach ($venc as $p) { $d = $p->fecha_vto->diffInDays(today()); $diasMax = max($diasMax, $d); $mora += (float) $p->saldo * (float) $c->interes_mora / 100 / 30 * $d; }
            $ultimo = Envio::where('contact_id', $c->id)->where('tipo', 'recordatorio')->latest()->first();
            return ['id' => $c->id, 'nombre' => $c->name, 'email' => $c->email, 'telefono' => $c->mobile ?: $c->phone, 'saldo' => (float) $c->balance, 'vencido' => round($venc->sum(fn($p) => (float) $p->saldo), 2), 'dias' => $diasMax, 'mora' => round($mora, 2), 'interes_mora' => (float) $c->interes_mora, 'vendedor' => $c->vendedor?->nombre, 'facturas' => $venc->count(), 'ultimo_aviso' => $ultimo?->created_at->format('d/m'), 'plan' => PlanPago::where('contact_id', $c->id)->where('estado', 'vigente')->exists()];
        })->values();
    }

    // Recordatorio de una factura por el canal elegido, con texto de la empresa.
    public function recordar(Comprobante $c, string $canal): Envio
    {
        $b = Auth::user()->business; $cfg = $this->config($b);
        $dias = $c->fecha_vto ? $c->fecha_vto->diffInDays(today(), false) : 0;
        $estado = $dias > 0 ? "venció hace {$dias} días" : ($dias === 0 ? 'vence hoy' : 'vence en ' . abs($dias) . ' días');
        $texto = strtr($cfg['texto'], ['{cliente}' => $c->contact?->name, '{comprobante}' => "{$c->nombreTipo()} {$c->numeroFormateado()}", '{importe}' => '$ ' . number_format((float) $c->saldo, 2, ',', '.'), '{estado}' => $estado, '{link}' => $c->urlPublica(), '{empresa}' => $b->name]);
        $destino = $canal === 'mail' ? $c->contact?->email : ($c->contact?->mobile ?: $c->contact?->phone);
        return $this->envios->enviar($c, $canal, $destino, 'recordatorio', $texto, false);
    }

    // Corre todos los días: manda los recordatorios que tocan según la configuración de cada empresa.
    public function correrAutomaticos(): int
    {
        $n = 0;
        foreach (Business::where('is_active', true)->whereNotNull('recordatorios')->get() as $b) {
            $cfg = $this->config($b);
            if (! ($cfg['activo'] ?? false)) continue;
            $user = User::where('business_id', $b->id)->whereNotNull('role_id')->orderBy('id')->first();
            if (! $user) continue;
            $prev = Auth::user(); Auth::setUser($user);
            $facturas = Comprobante::ventas()->pendientesCobro()->facturas()->where('condicion', 'cta_cte')->whereNotNull('fecha_vto')->with('contact')->get();
            foreach ($facturas as $c) {
                $dias = $c->fecha_vto->diffInDays(today(), false);
                if (! in_array($dias, array_map('intval', $cfg['dias']), true)) continue;
                if (Envio::where('modelo', 'Comprobante')->where('modelo_id', $c->id)->where('tipo', 'recordatorio')->whereDate('created_at', today())->exists()) continue;
                foreach ($cfg['canales'] as $canal) { $this->recordar($c, $canal); $n++; }
            }
            if ($prev) Auth::setUser($prev); else Auth::logout();
        }
        return $n;
    }

    // Refinanciación: nota de débito por el interés y plan en cuotas sobre las facturas vencidas elegidas.
    public function refinanciar(Contact $c, array $d): PlanPago
    {
        return DB::transaction(function () use ($c, $d) {
            $user = Auth::user();
            $facturas = Comprobante::where('contact_id', $c->id)->pendientesCobro()->whereIn('id', $d['comprobantes'] ?? [])->get();
            if ($facturas->isEmpty()) throw ValidationException::withMessages(['comprobantes' => 'Elegí las facturas a refinanciar.']);
            $deuda = round($facturas->sum(fn($f) => (float) $f->saldo), 2);
            $pct = (float) ($d['interes_pct'] ?? 0); $interes = round($deuda * $pct / 100, 2); $n = max(1, (int) ($d['cuotas'] ?? 1));
            $nd = null;
            if ($interes > 0) {
                $nd = $this->comprobantes->guardarBorrador(['contact_id' => $c->id, 'tipo' => 'NDX', 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'notas' => "Interés por refinanciación de " . $facturas->map(fn($f) => $f->numeroFormateado())->implode(', '), 'items' => [['descripcion' => "Interés por refinanciación ({$pct}%) en {$n} cuotas", 'cantidad' => 1, 'precio_unit' => round($interes / 1.21, 4), 'descuento' => 0, 'alicuota_iva' => 21]]]);
                $nd = $this->comprobantes->emitir($nd);
                $interes = (float) $nd->total;
            }
            $total = round($deuda + $interes, 2);
            $plan = PlanPago::create(['business_id' => $user->business_id, 'contact_id' => $c->id, 'user_id' => $user->id, 'numero' => ((int) PlanPago::withoutGlobalScopes()->where('business_id', $user->business_id)->max('numero')) + 1, 'fecha' => today(), 'deuda' => $deuda, 'interes_pct' => $pct, 'interes' => $interes, 'total' => $total, 'cuotas_n' => $n, 'nd_comprobante_id' => $nd?->id, 'notas' => $d['notas'] ?? null]);
            $primera = \Carbon\Carbon::parse($d['primer_vencimiento'] ?? today()->addDays(30));
            $cuota = round($total / $n, 2); $acum = 0;
            for ($i = 1; $i <= $n; $i++) {
                $monto = $i === $n ? round($total - $acum, 2) : $cuota; $acum += $monto;
                $plan->cuotas()->create(['numero' => $i, 'vencimiento' => $primera->copy()->addMonths($i - 1), 'monto' => $monto]);
            }
            // Las facturas refinanciadas dejan de estar vencidas: su vencimiento pasa a la última cuota.
            Comprobante::whereIn('id', $facturas->pluck('id'))->update(['fecha_vto' => $primera->copy()->addMonths($n - 1)]);
            \App\Models\CuentaCorriente::whereIn('comprobante_id', $facturas->pluck('id'))->update(['fecha_vto' => $primera->copy()->addMonths($n - 1)]);
            if ($nd) $nd->forceFill(['fecha_vto' => $primera->copy()->addMonths($n - 1)])->save();
            AuditLog::registrar('crear', $plan, "Plan de pago {$plan->numeroFormateado()} a {$c->name}: $ " . number_format($total, 2, ',', '.') . " en {$n} cuotas");
            return $plan->fresh('cuotas');
        });
    }

    // Actualiza cuotas según lo cobrado al cliente desde la fecha del plan (se imputa en orden).
    public function actualizarCuotas(PlanPago $plan): void
    {
        $cobrado = (float) \App\Models\Cobro::where('contact_id', $plan->contact_id)->where('estado', '!=', 'anulado')->where('fecha', '>=', $plan->fecha)->sum('total');
        foreach ($plan->cuotas as $q) {
            $pag = min((float) $q->monto, max(0, $cobrado)); $cobrado -= $pag;
            $q->update(['pagado' => $pag, 'estado' => $pag >= (float) $q->monto - 0.005 ? 'pagada' : ($q->vencimiento->lt(today()) ? 'vencida' : 'pendiente')]);
        }
        if ($plan->cuotas()->where('estado', '!=', 'pagada')->doesntExist()) $plan->update(['estado' => 'pagado']);
    }
}
