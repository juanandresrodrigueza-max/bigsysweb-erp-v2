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

    // Deudores con vencido, días de mora y mora calculada: una sola consulta agrupada sobre comprobantes (escala a miles de clientes).
    public function deudores(int $limite = 400): \Illuminate\Support\Collection
    {
        $hoy = today()->toDateString();
        $dias = \App\Support\Sql::diasHasta('fecha_vto', $hoy); // días desde el vencimiento hasta hoy
        $agg = Comprobante::ventas()->pendientesCobro()
            ->selectRaw("contact_id, SUM(saldo) as saldo, SUM(CASE WHEN fecha_vto < ? THEN saldo ELSE 0 END) as vencido, MAX(CASE WHEN fecha_vto < ? THEN {$dias} ELSE 0 END) as dias, SUM(CASE WHEN fecha_vto < ? THEN saldo * ({$dias}) ELSE 0 END) as saldo_dias, SUM(CASE WHEN fecha_vto < ? THEN 1 ELSE 0 END) as facturas", [$hoy, $hoy, $hoy, $hoy])
            ->groupBy('contact_id')->orderByDesc('saldo')->limit($limite)->get()->keyBy('contact_id');
        if ($agg->isEmpty()) return collect();
        $ultimos = Envio::whereIn('contact_id', $agg->keys())->where('tipo', 'recordatorio')->selectRaw('contact_id, MAX(created_at) as ultimo')->groupBy('contact_id')->pluck('ultimo', 'contact_id');
        $planes = PlanPago::where('estado', 'vigente')->whereIn('contact_id', $agg->keys())->pluck('contact_id')->flip();
        return Contact::whereIn('id', $agg->keys())->with('vendedor:id,nombre')->get()->map(function ($c) use ($agg, $ultimos, $planes) {
            $a = $agg[$c->id];
            $mora = (float) $a->saldo_dias * (float) $c->interes_mora / 100 / 30;
            return ['id' => $c->id, 'nombre' => $c->name, 'email' => $c->email, 'telefono' => $c->mobile ?: $c->phone, 'saldo' => round((float) $a->saldo, 2), 'vencido' => round((float) $a->vencido, 2), 'dias' => (int) round((float) $a->dias), 'mora' => round($mora, 2), 'interes_mora' => (float) $c->interes_mora, 'vendedor' => $c->vendedor?->nombre, 'facturas' => (int) $a->facturas, 'ultimo_aviso' => isset($ultimos[$c->id]) ? \Carbon\Carbon::parse($ultimos[$c->id])->format('d/m') : null, 'plan' => isset($planes[$c->id])];
        })->sortByDesc('saldo')->values();
    }

    // Totales de toda la cartera (no solo de los deudores listados): por cobrar, vencido, mora estimada y cantidad de deudores con vencido.
    public function resumenDeudores(): array
    {
        $hoy = today()->toDateString(); $dias = \App\Support\Sql::diasHasta('comprobantes.fecha_vto', $hoy);
        $r = Comprobante::ventas()->pendientesCobro()->join('contacts', 'contacts.id', '=', 'comprobantes.contact_id')
            ->selectRaw("COALESCE(SUM(comprobantes.saldo),0) as por_cobrar, COALESCE(SUM(CASE WHEN comprobantes.fecha_vto < ? THEN comprobantes.saldo ELSE 0 END),0) as vencido, COALESCE(SUM(CASE WHEN comprobantes.fecha_vto < ? THEN comprobantes.saldo * ({$dias}) * contacts.interes_mora / 100 / 30 ELSE 0 END),0) as mora, COUNT(DISTINCT CASE WHEN comprobantes.fecha_vto < ? THEN comprobantes.contact_id END) as deudores", [$hoy, $hoy, $hoy])->first();
        return ['por_cobrar' => round((float) $r->por_cobrar, 2), 'vencido' => round((float) $r->vencido, 2), 'mora' => round((float) $r->mora, 2), 'deudores' => (int) $r->deudores];
    }

    // Recordatorio de una factura por el canal elegido, con texto de la empresa.
    public function recordar(Comprobante $c, string $canal): Envio
    {
        $b = Auth::user()->business; $cfg = $this->config($b);
        $dias = $c->fecha_vto ? $c->fecha_vto->diffInDays(today(), false) : 0;
        $estado = $dias > 0 ? "venció hace {$dias} días" : ($dias === 0 ? 'vence hoy' : 'vence en ' . abs($dias) . ' días');
        $texto = strtr($cfg['texto'], ['{cliente}' => $c->contact?->name, '{comprobante}' => "{$c->nombreTipo()} {$c->numeroFormateado()}", '{importe}' => '$ ' . number_format((float) $c->saldo, 2, ',', '.'), '{estado}' => $estado, '{link}' => $c->urlPublica(), '{empresa}' => $b->name]);
        if ($canal === 'crm') {
            // Por el CRM: queda como tarea de cobranza para el vendedor, que la manda por la conversación del cliente (WhatsApp, mail) desde allá.
            $envio = Envio::create(['business_id' => $b->id, 'user_id' => Auth::id(), 'contact_id' => $c->contact_id, 'modelo' => 'Comprobante', 'modelo_id' => $c->id, 'canal' => 'crm', 'tipo' => 'recordatorio', 'destino' => 'CRM', 'asunto' => "Cobranza {$c->nombreTipo()} {$c->numeroFormateado()}", 'cuerpo' => $texto, 'estado' => 'pendiente']);
            try { $id = \App\Services\Integraciones\CrmSyncService::tareaCobranza($b, $c->contact, "Cobrar {$c->nombreTipo()} {$c->numeroFormateado()} · " . $c->contact?->name, $texto); $envio->update(['estado' => 'enviado', 'enviado_en' => now(), 'link' => $id ? '/integraciones/crm/ir?a=' . urlencode('/tasks') : null]); }
            catch (\Throwable $e) { $envio->update(['estado' => 'error', 'error' => mb_substr($e->getMessage(), 0, 250)]); }
            return $envio;
        }
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
