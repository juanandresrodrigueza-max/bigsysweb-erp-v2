<?php

namespace App\Services\Contabilidad;

use App\Models\Asiento;
use App\Models\AuditLog;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\CuentaContable;
use App\Models\CuentaFondos;
use App\Models\ExpenseCategory;
use App\Models\MovimientoFondos;
use App\Models\Pago;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Arma los asientos contables a partir de lo que ya pasó en el sistema (ventas, compras, cobros, pagos, fondos).
// Nunca frena una operación: si algo falla se registra en el log y el asiento se puede regenerar con "Sincronizar".
class ContabilidadService
{
    private array $cache = [];

    // Punto de entrada: contabiliza cualquier modelo soportado (idempotente por origen + id).
    public function contabilizar(Model $m): ?Asiento
    {
        try {
            return match (true) {
                $m instanceof Comprobante => $m->direccion === 'compra' ? $this->asientoCompra($m) : $this->asientoVenta($m),
                $m instanceof Cobro => $this->asientoCobro($m),
                $m instanceof Pago => $this->asientoPago($m),
                $m instanceof MovimientoFondos => $this->asientoFondos($m),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('Contabilidad: no se pudo generar el asiento', ['modelo' => get_class($m), 'id' => $m->getKey(), 'error' => $e->getMessage()]);
            return null;
        }
    }

    // Anula el asiento de una operación anulada (queda como registro, no suma en los libros).
    public function anular(string $origen, int $origenId, string $motivo = 'Operación anulada'): void
    {
        Asiento::withoutGlobalScopes()->where('origen', $origen)->where('origen_id', $origenId)->where('estado', 'confirmado')
            ->get()->each(fn($a) => $a->update(['estado' => 'anulado', 'concepto' => $a->concepto . " (anulado: {$motivo})"]));
    }

    // Recorre todo lo emitido sin asiento y lo contabiliza. Devuelve cuántos creó.
    public function sincronizar(int $businessId): int
    {
        $n = 0;
        $tiene = fn($origen, $id) => Asiento::withoutGlobalScopes()->where('business_id', $businessId)->where('origen', $origen)->where('origen_id', $id)->exists();

        foreach (Comprobante::withoutGlobalScopes()->where('business_id', $businessId)->where('estado', 'emitido')->whereIn('tipo', array_keys(array_filter(Comprobante::TIPOS, fn($t) => $t['cc'] !== 0)))->orderBy('fecha')->orderBy('id')->cursor() as $c) {
            if (! $tiene($c->direccion === 'compra' ? 'compra' : 'venta', $c->id) && $this->contabilizar($c)) $n++;
        }
        foreach (Cobro::withoutGlobalScopes()->where('business_id', $businessId)->where('estado', '!=', 'anulado')->orderBy('fecha')->cursor() as $c) {
            if (! $tiene('cobro', $c->id) && $this->contabilizar($c)) $n++;
        }
        foreach (Pago::withoutGlobalScopes()->where('business_id', $businessId)->where('estado', '!=', 'anulado')->orderBy('fecha')->cursor() as $p) {
            if (! $tiene('pago', $p->id) && $this->contabilizar($p)) $n++;
        }
        foreach (MovimientoFondos::withoutGlobalScopes()->where('business_id', $businessId)->whereIn('origen', ['gasto', 'ingreso', 'transferencia', 'ajuste', 'apertura', 'cheque'])->orderBy('fecha')->orderBy('id')->cursor() as $m) {
            if (! $tiene('fondos', $m->id) && $this->contabilizar($m)) $n++;
        }
        return $n;
    }

    // ---- Asientos automáticos ------------------------------------------------------------

    private function asientoVenta(Comprobante $c): ?Asiento
    {
        $def = $c->def();
        if ($c->estado !== 'emitido' || $def['cc'] === 0) return null;
        $signo = $def['cc']; // 1 factura/ND, -1 NC
        $cli = $c->contact;
        $lineas = [];
        $neto = (float) $c->neto - (float) $c->descuento; $exento = (float) $c->exento; $iva = (float) $c->iva; $perc = (float) $c->percepciones; $total = (float) $c->total;
        $this->linea($lineas, 'deudores', $signo * $total, 0, $cli?->name, $c->contact_id);
        $this->linea($lineas, 'ventas', 0, $signo * ($neto + $exento), $c->nombreTipo() . ' ' . $c->numeroFormateado());
        if ($iva) $this->linea($lineas, 'iva_df', 0, $signo * $iva, 'IVA');
        if ($perc) $this->linea($lineas, 'percepciones_cobradas', 0, $signo * $perc, 'Percepciones');

        // Costo de lo vendido (facturas y NC con artículos con stock): CMV a Mercaderías.
        if ($def['stock'] && ! $c->es_acopio) {
            $cmv = 0;
            foreach ($c->items as $it) {
                if ($it->product_id && ($p = Product::withoutGlobalScopes()->find($it->product_id)) && $p->controla_stock) $cmv += (float) $it->cantidad * (float) $p->cost;
            }
            if ($cmv > 0.005) {
                $this->linea($lineas, 'cmv', $signo * $cmv, 0, 'Costo de venta');
                $this->linea($lineas, 'mercaderias', 0, $signo * $cmv, 'Salida de mercaderías');
            }
        }
        return $this->crear($c->business_id, $c->business_location_id, $c->fecha, "{$c->nombreTipo()} {$c->numeroFormateado()} · " . ($cli?->name ?? 'Consumidor final'), 'venta', $c->id, $lineas);
    }

    private function asientoCompra(Comprobante $c): ?Asiento
    {
        $def = $c->def();
        if ($c->estado !== 'emitido' || $def['cc'] === 0) return null;
        $signo = $def['cc'];
        $prov = $c->contact;
        $lineas = [];
        $mercaderias = 0; $gastos = 0;
        foreach ($c->items as $it) {
            $neto = (float) $it->neto;
            if ($it->product_id && ($p = Product::withoutGlobalScopes()->find($it->product_id)) && $p->controla_stock) $mercaderias += $neto; else $gastos += $neto;
        }
        $iva = (float) $c->iva; $perc = (float) $c->percepciones; $total = (float) $c->total;
        if ($mercaderias) $this->linea($lineas, 'mercaderias', $signo * $mercaderias, 0, 'Compra de mercaderías');
        if ($gastos) $this->linea($lineas, 'compras_gastos', $signo * $gastos, 0, 'Compras no inventariables');
        if ($iva) $this->linea($lineas, 'iva_cf', $signo * $iva, 0, 'IVA crédito fiscal');
        if ($perc) $this->linea($lineas, 'ret_sufridas', $signo * $perc, 0, 'Percepciones sufridas');
        $this->linea($lineas, 'proveedores', 0, $signo * $total, $prov?->name, $c->contact_id);
        return $this->crear($c->business_id, $c->business_location_id, $c->fecha, "Compra {$c->nombreTipo()} {$c->numeroFormateado()} · " . ($prov?->name ?? ''), 'compra', $c->id, $lineas);
    }

    private function asientoCobro(Cobro $cobro): ?Asiento
    {
        if ($cobro->estado === 'anulado') return null;
        $lineas = [];
        foreach ($cobro->medios as $m) {
            $monto = (float) $m->monto;
            $clave = match ($m->medio) {
                'cheque' => 'cheques_cartera',
                'retencion' => 'ret_sufridas',
                'tarjeta' => 'tarjetas_cobrar',
                default => $this->claveCuentaFondos($m->cuenta_fondos_id, $m->medio),
            };
            $this->linea($lineas, $clave, $monto, 0, Cobro::MEDIOS[$m->medio] ?? $m->medio);
        }
        $this->linea($lineas, 'deudores', 0, (float) $cobro->total, $cobro->contact?->name, $cobro->contact_id);
        return $this->crear($cobro->business_id, $cobro->business_location_id, $cobro->fecha, "Cobro {$cobro->numeroFormateado()} · {$cobro->contact?->name}", 'cobro', $cobro->id, $lineas);
    }

    private function asientoPago(Pago $pago): ?Asiento
    {
        if ($pago->estado === 'anulado') return null;
        $lineas = [];
        $this->linea($lineas, 'proveedores', (float) $pago->total, 0, $pago->contact?->name, $pago->contact_id);
        foreach ($pago->medios as $m) {
            $monto = (float) $m->monto;
            $clave = match ($m->medio) {
                'cheque_propio' => 'cheques_propios',
                'cheque_tercero' => 'cheques_cartera',
                'retencion' => 'ret_practicadas',
                default => $this->claveCuentaFondos($m->cuenta_fondos_id, $m->medio),
            };
            $this->linea($lineas, $clave, 0, $monto, Pago::MEDIOS[$m->medio] ?? $m->medio);
        }
        return $this->crear($pago->business_id, $pago->business_location_id, $pago->fecha, "Pago {$pago->numeroFormateado()} · {$pago->contact?->name}", 'pago', $pago->id, $lineas);
    }

    // Movimientos de fondos que no vienen de cobros ni pagos (gastos, ingresos, transferencias, ajustes, cheques).
    private function asientoFondos(MovimientoFondos $m): ?Asiento
    {
        if (in_array($m->origen, ['cobro', 'pago'], true)) return null;
        $cuenta = CuentaFondos::withoutGlobalScopes()->find($m->cuenta_fondos_id);
        $claveCuenta = $this->claveCuentaFondos($m->cuenta_fondos_id, null);
        $monto = (float) $m->ingreso - (float) $m->egreso;
        $lineas = [];
        $contra = match ($m->origen) {
            'gasto' => $m->expense_category_id ? $this->claveCategoria($m->business_id, $m->expense_category_id) : 'gastos',
            'ingreso' => 'otros_ingresos',
            'transferencia' => 'transito',
            // El saldo con el que arranca una cuenta es aporte de capital; las diferencias de caja son resultado.
            'ajuste', 'apertura' => str_starts_with(mb_strtolower($m->concepto), 'saldo inicial') ? 'capital' : ($monto >= 0 ? 'sobrante_caja' : 'faltante_caja'),
            'cheque' => $this->claveCheque($m),
            default => 'otros_ingresos',
        };
        if ($monto >= 0) {
            $this->linea($lineas, $claveCuenta, $monto, 0, $cuenta?->nombre);
            $this->linea($lineas, $contra, 0, $monto, $m->concepto);
        } else {
            $this->linea($lineas, $contra, -$monto, 0, $m->concepto);
            $this->linea($lineas, $claveCuenta, 0, -$monto, $cuenta?->nombre);
        }
        return $this->crear($m->business_id, $cuenta?->business_location_id, $m->fecha, $m->concepto . ($cuenta ? " · {$cuenta->nombre}" : ''), 'fondos', $m->id, $lineas);
    }

    // ---- Helpers ------------------------------------------------------------------------

    private function claveCuentaFondos(?int $cuentaId, ?string $medio): string
    {
        $tipo = $cuentaId ? CuentaFondos::withoutGlobalScopes()->find($cuentaId)?->tipo : null;
        $tipo ??= match ($medio) { 'efectivo' => 'caja', 'billetera', 'mercadopago' => 'billetera', default => 'banco' };
        return match ($tipo) { 'caja' => 'caja', 'billetera' => 'billetera', default => 'banco' };
    }

    private function claveCategoria(int $businessId, int $catId): string
    {
        $cat = ExpenseCategory::withoutGlobalScopes()->find($catId);
        return $cat ? PlanCuentas::cuentaParaCategoria($businessId, $cat)->clave : 'gastos';
    }

    // Depósito de cheque de tercero: Banco a Cheques en cartera. Débito de cheque propio: Cheques propios a Banco. Rechazo: vuelve.
    private function claveCheque(MovimientoFondos $m): string
    {
        $ch = \App\Models\Cheque::withoutGlobalScopes()->find($m->origen_id);
        if (! $ch) return 'transito';
        return $ch->tipo === 'propio' ? 'cheques_propios' : 'cheques_cartera';
    }

    private function linea(array &$lineas, string $clave, float $debe, float $haber, ?string $detalle = null, ?int $contactId = null): void
    {
        // Montos negativos (NC) se dan vuelta: un debe negativo es un haber.
        if ($debe < 0) { $haber = -$debe; $debe = 0; } elseif ($haber < 0) { $debe = -$haber; $haber = 0; }
        if (abs($debe) < 0.005 && abs($haber) < 0.005) return;
        $lineas[] = ['clave' => $clave, 'debe' => round($debe, 2), 'haber' => round($haber, 2), 'detalle' => $detalle ? mb_substr($detalle, 0, 190) : null, 'contact_id' => $contactId];
    }

    private function cuenta(int $businessId, string $clave): CuentaContable
    {
        return $this->cache["$businessId:$clave"] ??= (
            CuentaContable::withoutGlobalScopes()->where('business_id', $businessId)->where('clave', $clave)->first()
            ?? tap(null, function () use ($businessId) { PlanCuentas::crear(\App\Models\Business::find($businessId)); })
            ?? CuentaContable::withoutGlobalScopes()->where('business_id', $businessId)->where('clave', $clave)->firstOrFail()
        );
    }

    private function crear(int $businessId, ?int $locationId, $fecha, string $concepto, string $origen, ?int $origenId, array $lineas, ?int $userId = null): ?Asiento
    {
        if (! $lineas) return null;
        if ($origenId && Asiento::withoutGlobalScopes()->where('business_id', $businessId)->where('origen', $origen)->where('origen_id', $origenId)->where('estado', 'confirmado')->exists()) {
            return null; // ya contabilizado
        }
        $debe = round(array_sum(array_column($lineas, 'debe')), 2); $haber = round(array_sum(array_column($lineas, 'haber')), 2);
        if (abs($debe - $haber) > 0.02) {
            // Diferencia de redondeo: se ajusta contra resultados para que el asiento cierre.
            $dif = round($debe - $haber, 2);
            $lineas[] = ['clave' => 'resultados', 'debe' => $dif < 0 ? -$dif : 0, 'haber' => $dif > 0 ? $dif : 0, 'detalle' => 'Ajuste de redondeo', 'contact_id' => null];
        }
        return DB::transaction(function () use ($businessId, $locationId, $fecha, $concepto, $origen, $origenId, $lineas, $userId, $debe) {
            $a = Asiento::withoutGlobalScopes()->create([
                'business_id' => $businessId, 'business_location_id' => $locationId, 'user_id' => $userId ?? Auth::id(),
                'numero' => (Asiento::withoutGlobalScopes()->where('business_id', $businessId)->max('numero') ?? 0) + 1,
                'fecha' => $fecha, 'concepto' => mb_substr($concepto, 0, 250), 'origen' => $origen, 'origen_id' => $origenId, 'total' => max($debe, round(array_sum(array_column($lineas, 'haber')), 2)),
            ]);
            foreach ($lineas as $l) {
                $a->lineas()->create(['cuenta_id' => $this->cuenta($businessId, $l['clave'])->id, 'debe' => $l['debe'], 'haber' => $l['haber'], 'detalle' => $l['detalle'], 'contact_id' => $l['contact_id']]);
            }
            return $a;
        });
    }

    // Asiento manual del contador: recibe líneas con cuenta_id.
    public function manual(array $d): Asiento
    {
        $user = Auth::user();
        $debe = round(collect($d['lineas'])->sum('debe'), 2); $haber = round(collect($d['lineas'])->sum('haber'), 2);
        abort_if(abs($debe - $haber) > 0.005, 422, "El asiento no balancea: debe {$debe} ≠ haber {$haber}.");
        abort_if($debe <= 0, 422, 'El asiento está vacío.');
        return DB::transaction(function () use ($d, $user, $debe) {
            $a = Asiento::create(['business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'user_id' => $user->id, 'numero' => (Asiento::withoutGlobalScopes()->where('business_id', $user->business_id)->max('numero') ?? 0) + 1, 'fecha' => $d['fecha'], 'concepto' => $d['concepto'], 'origen' => 'manual', 'total' => $debe]);
            foreach ($d['lineas'] as $l) {
                if ((float) ($l['debe'] ?? 0) <= 0 && (float) ($l['haber'] ?? 0) <= 0) continue;
                $a->lineas()->create(['cuenta_id' => $l['cuenta_id'], 'debe' => (float) ($l['debe'] ?? 0), 'haber' => (float) ($l['haber'] ?? 0), 'detalle' => $l['detalle'] ?? null]);
            }
            AuditLog::registrar('crear', $a, "Asiento manual {$a->numeroFormateado()}: {$a->concepto}");
            return $a;
        });
    }

    // ---- Reportes -----------------------------------------------------------------------

    // Saldos por cuenta en un rango (sumas y saldos). Devuelve [cuenta_id => [debe, haber]].
    public function sumas(int $businessId, ?string $desde, ?string $hasta): array
    {
        $q = DB::table('asiento_lineas')->join('asientos', 'asientos.id', '=', 'asiento_lineas.asiento_id')
            ->where('asientos.business_id', $businessId)->where('asientos.estado', 'confirmado')
            ->when($desde, fn($q) => $q->where('asientos.fecha', '>=', $desde))->when($hasta, fn($q) => $q->where('asientos.fecha', '<=', $hasta))
            ->groupBy('asiento_lineas.cuenta_id')->selectRaw('asiento_lineas.cuenta_id, SUM(debe) as debe, SUM(haber) as haber')->get();
        return $q->mapWithKeys(fn($r) => [$r->cuenta_id => ['debe' => (float) $r->debe, 'haber' => (float) $r->haber]])->all();
    }

    // Estado de resultados del período: ingresos, egresos por cuenta y resultado.
    public function resultado(int $businessId, string $desde, string $hasta): array
    {
        $sumas = $this->sumas($businessId, $desde, $hasta);
        $cuentas = CuentaContable::withoutGlobalScopes()->where('business_id', $businessId)->whereIn('tipo', ['ingreso', 'egreso'])->where('imputable', true)->orderBy('codigo')->get();
        $ingresos = []; $egresos = [];
        foreach ($cuentas as $c) {
            $s = $sumas[$c->id] ?? null;
            if (! $s) continue;
            $saldo = $c->tipo === 'ingreso' ? $s['haber'] - $s['debe'] : $s['debe'] - $s['haber'];
            if (abs($saldo) < 0.005) continue;
            $fila = ['id' => $c->id, 'codigo' => $c->codigo, 'nombre' => $c->nombre, 'monto' => round($saldo, 2)];
            $c->tipo === 'ingreso' ? $ingresos[] = $fila : $egresos[] = $fila;
        }
        $ti = round(array_sum(array_column($ingresos, 'monto')), 2); $te = round(array_sum(array_column($egresos, 'monto')), 2);
        return ['ingresos' => $ingresos, 'egresos' => $egresos, 'total_ingresos' => $ti, 'total_egresos' => $te, 'resultado' => round($ti - $te, 2)];
    }
}
