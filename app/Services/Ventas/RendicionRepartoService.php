<?php

namespace App\Services\Ventas;

use App\Models\AuditLog;
use App\Models\CuentaFondos;
use App\Models\OrdenEntrega;
use App\Models\OrdenEntregaCobro;
use App\Models\OrdenEntregaItem;
use App\Services\Comprobantes\CobroService;
use App\Services\Fondos\FondosService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Fase 25.5: rendición del reparto. El chofer anota lo cobrado en cada entrega y al volver rinde el viaje.
class RendicionRepartoService
{
    public function __construct(private CobroService $cobros, private FondosService $fondos) {}

    public function anotarCobro(OrdenEntregaItem $it, array $d): OrdenEntregaCobro
    {
        $oe = $it->hoja;
        abort_if($oe->rendida_en, 422, 'La hoja ya se rindió: no se pueden anotar más cobros.');
        abort_if($oe->estado === 'cancelada', 422, 'La hoja está cancelada.');
        $c = $it->comprobante;
        return OrdenEntregaCobro::create(['business_id' => $oe->business_id, 'orden_entrega_id' => $oe->id, 'orden_entrega_item_id' => $it->id, 'comprobante_id' => $c?->id, 'contact_id' => $c->contact_id,
            'medio' => $d['medio'], 'monto' => round((float) $d['monto'], 2), 'referencia' => $d['referencia'] ?? null, 'datos' => $d['medio'] === 'cheque' ? array_filter(['banco' => $d['banco'] ?? null, 'numero' => $d['numero'] ?? null, 'fecha_pago' => $d['fecha_pago'] ?? null]) : null, 'user_id' => Auth::id()]);
    }

    public function quitarCobro(OrdenEntregaCobro $c): void
    {
        abort_if($c->cobro_id, 422, 'Ese cobro ya se rindió.');
        $c->delete();
    }

    // Lo que el chofer tiene que rendir, por medio.
    public function esperado(OrdenEntrega $oe): array
    {
        $por = $oe->cobros()->whereNull('cobro_id')->get()->groupBy('medio')->map(fn($g) => round($g->sum('monto'), 2));
        return collect(OrdenEntregaCobro::MEDIOS)->mapWithKeys(fn($l, $k) => [$k => (float) ($por[$k] ?? 0)])->all();
    }

    // Rinde el viaje: un recibo por comprobante con sus medios, viáticos como gasto de la caja y la diferencia de efectivo.
    public function rendir(OrdenEntrega $oe, array $d): OrdenEntrega
    {
        return DB::transaction(function () use ($oe, $d) {
            $oe = OrdenEntrega::lockForUpdate()->findOrFail($oe->id);
            if ($oe->rendida_en) throw ValidationException::withMessages(['rendir' => 'Esta hoja ya se rindió.']);
            if ($oe->items()->where('estado', 'pendiente')->exists()) throw ValidationException::withMessages(['rendir' => 'Todavía hay entregas sin marcar. Marcalas como entregadas o no entregadas antes de rendir.']);
            $caja = CuentaFondos::where('tipo', 'caja')->findOrFail($d['cuenta_fondos_id']);
            $banco = ! empty($d['cuenta_banco_id']) ? CuentaFondos::findOrFail($d['cuenta_banco_id']) : null;
            $esperado = $this->esperado($oe);
            $viaticos = collect($d['viaticos'] ?? [])->filter(fn($v) => (float) ($v['monto'] ?? 0) > 0 && trim((string) ($v['concepto'] ?? '')) !== '')->values();
            $totalViaticos = round($viaticos->sum(fn($v) => (float) $v['monto']), 2);
            $debeEfectivo = round($esperado['efectivo'] - $totalViaticos, 2);
            $contado = round((float) $d['efectivo_contado'], 2);
            $diferencia = round($contado - $debeEfectivo, 2);
            $hr = $oe->numeroFormateado();
            $fecha = today()->toDateString();

            // Un recibo por comprobante (o por cliente, si se cobró algo suelto), imputado a lo que se entregó.
            $recibos = [];
            foreach ($oe->cobros()->whereNull('cobro_id')->with('contact', 'comprobante')->get()->groupBy(fn($c) => $c->comprobante_id ? "c{$c->comprobante_id}" : "k{$c->contact_id}") as $grupo) {
                $contact = $grupo->first()->contact; $comp = $grupo->first()->comprobante;
                $total = round($grupo->sum('monto'), 2);
                $medios = $grupo->map(fn($c) => ['medio' => $c->medio, 'monto' => (float) $c->monto, 'referencia' => $c->referencia, 'datos' => $c->datos,
                    'cuenta_fondos_id' => $c->medio === 'efectivo' ? $caja->id : ($c->medio === 'cheque' ? null : $banco?->id)])->all();
                $imp = $comp && (float) $comp->saldo > 0 ? [['comprobante_id' => $comp->id, 'monto' => min($total, (float) $comp->saldo)]] : [];
                $cobro = $this->cobros->registrar($contact, ['fecha' => $fecha, 'medios' => $medios, 'imputaciones' => $imp, 'notas' => "Rendición {$hr}" . ($oe->repartidor ? " · {$oe->repartidor}" : '')]);
                OrdenEntregaCobro::whereIn('id', $grupo->pluck('id'))->update(['cobro_id' => $cobro->id]);
                $recibos[] = $cobro->numeroFormateado();
            }
            // Viáticos del chofer: salen del efectivo que trae, como gasto de la caja.
            foreach ($viaticos as $v) {
                $this->fondos->registrar($caja, ['fecha' => $fecha, 'origen' => 'gasto', 'expense_category_id' => $v['expense_category_id'] ?? null, 'concepto' => "Viático {$hr}: " . trim($v['concepto']), 'egreso' => (float) $v['monto'], 'referencia' => $hr]);
            }
            // Faltante o sobrante de efectivo.
            if (abs($diferencia) >= 0.01) {
                $this->fondos->registrar($caja, ['fecha' => $fecha, 'origen' => 'ajuste', 'concepto' => ($diferencia < 0 ? 'Faltante' : 'Sobrante') . " de rendición {$hr}" . ($oe->repartidor ? " · {$oe->repartidor}" : ''), $diferencia < 0 ? 'egreso' : 'ingreso' => abs($diferencia), 'referencia' => $hr]);
            }

            $oe->forceFill(['rendida_en' => now(), 'rendida_por' => Auth::id(), 'cuenta_fondos_id' => $caja->id, 'estado' => $oe->estado === 'cancelada' ? 'cancelada' : 'entregada',
                'rendicion' => ['esperado' => $esperado, 'viaticos' => $viaticos->map(fn($v) => ['concepto' => trim($v['concepto']), 'monto' => round((float) $v['monto'], 2)])->all(), 'total_viaticos' => $totalViaticos,
                    'debe_efectivo' => $debeEfectivo, 'contado' => $contado, 'diferencia' => $diferencia, 'recibos' => $recibos, 'banco' => $banco?->nombre, 'caja' => $caja->nombre, 'notas' => $d['notas'] ?? null]])->save();
            AuditLog::registrar('editar', $oe, "Rindió {$hr}: efectivo $ " . number_format($contado, 2, ',', '.') . ' · ' . count($recibos) . ' recibos' . (abs($diferencia) >= 0.01 ? ' · ' . ($diferencia < 0 ? 'faltante' : 'sobrante') . ' $ ' . number_format(abs($diferencia), 2, ',', '.') : ''));
            return $oe->fresh();
        });
    }
}
