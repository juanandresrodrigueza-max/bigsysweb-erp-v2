<?php

namespace App\Http\Controllers\Fondos;

use App\Http\Controllers\Controller;
use App\Models\Cheque;
use App\Models\CuponTarjeta;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Trazabilidad de valores: de dónde vino cada cheque o cupón, dónde está hoy y a dónde fue.
class ValoresController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->q);
        $cheques = Cheque::with(['contact:id,name', 'cuenta:id,nombre', 'cobro:id,numero,fecha,contact_id', 'cobro.contact:id,name', 'pago:id,numero,fecha,contact_id', 'pago.contact:id,name'])
            ->when($q, fn($qq) => $qq->where(fn($w) => $w->where('numero', 'like', "%{$q}%")->orWhere('emisor', 'like', "%{$q}%")->orWhere('banco', 'like', "%{$q}%")))
            ->when($request->estado, fn($qq, $e) => $qq->where('estado', $e))->when($request->tipo, fn($qq, $t) => $qq->where('tipo', $t))
            ->orderByDesc('fecha_pago')->limit(300)->get()->map(fn($c) => [
                'id' => $c->id, 'tipo' => $c->tipo, 'numero' => $c->numero, 'banco' => $c->banco, 'emisor' => $c->emisor, 'echeq' => $c->echeq, 'monto' => (float) $c->monto, 'fecha_pago' => $c->fecha_pago?->format('d/m/Y'), 'estado' => $c->estado, 'estado_label' => Cheque::ESTADOS[$c->estado] ?? $c->estado, 'fecha_estado' => $c->fecha_estado?->format('d/m/Y'),
                'origen' => $c->tipo === 'tercero' && $c->cobro ? "Recibo {$c->cobro->numero} · " . ($c->cobro->contact?->name ?? '') . ' · ' . $c->cobro->fecha->format('d/m/Y') : ($c->tipo === 'propio' ? 'Cheque propio · ' . ($c->cuenta?->nombre ?? '') : 'Carga manual'),
                'destino' => match ($c->estado) { 'depositado', 'cobrado' => 'Depositado en ' . ($c->cuenta?->nombre ?? 'banco') . ($c->fecha_estado ? ' el ' . $c->fecha_estado->format('d/m/Y') : ''), 'entregado', 'pagado' => $c->pago ? "Orden de pago {$c->pago->numero} · " . ($c->pago->contact?->name ?? '') . ' · ' . $c->pago->fecha->format('d/m/Y') : 'Entregado', 'rechazado' => 'Rechazado' . ($c->fecha_estado ? ' el ' . $c->fecha_estado->format('d/m/Y') : ''), 'anulado' => 'Anulado', default => 'En cartera' },
                'cobro_id' => $c->cobro_id, 'pago_id' => $c->pago_id, 'contact_id' => $c->contact_id ?? $c->cobro?->contact_id ?? $c->pago?->contact_id, 'notas' => $c->notas,
            ]);
        $cupones = CuponTarjeta::with(['contact:id,name', 'cobro:id,numero,fecha', 'liquidacion:id,fecha,cuenta_fondos_id', 'liquidacion.cuenta:id,nombre'])
            ->when($q, fn($qq) => $qq->where(fn($w) => $w->where('numero', 'like', "%{$q}%")->orWhere('lote', 'like', "%{$q}%")->orWhere('tarjeta', 'like', "%{$q}%")))
            ->when($request->estado, fn($qq, $e) => $qq->where('estado', $e))
            ->orderByDesc('fecha')->limit(300)->get()->map(fn($c) => [
                'id' => $c->id, 'tarjeta' => $c->tarjeta, 'numero' => $c->numero, 'lote' => $c->lote, 'cuotas' => $c->cuotas, 'monto' => (float) $c->monto, 'fecha' => $c->fecha->format('d/m/Y'), 'estado' => $c->estado,
                'origen' => $c->cobro ? "Recibo {$c->cobro->numero} · " . ($c->contact?->name ?? '') . ' · ' . $c->cobro->fecha->format('d/m/Y') : 'Manual',
                'destino' => $c->liquidacion ? 'Liquidación ' . $c->liquidacion->fecha->format('d/m/Y') . ' en ' . ($c->liquidacion->cuenta?->nombre ?? '') : ($c->estado === 'rechazado' ? 'Rechazado por la tarjeta' : 'Pendiente de liquidar'),
                'cobro_id' => $c->cobro_id, 'liquidacion_id' => $c->liquidacion_tarjeta_id, 'contact_id' => $c->contact_id,
            ]);
        return Inertia::render('Fondos/Valores', [
            'cheques' => $cheques, 'cupones' => $cupones, 'filtros' => $request->only('q', 'estado', 'tipo'), 'estadosCheque' => Cheque::ESTADOS,
            'resumen' => ['cartera' => (float) Cheque::enCartera()->sum('monto'), 'cartera_n' => Cheque::enCartera()->count(), 'propios' => (float) Cheque::propiosPendientes()->sum('monto'), 'cupones' => (float) CuponTarjeta::enCartera()->sum('monto'), 'cupones_n' => CuponTarjeta::enCartera()->count(), 'rechazados' => Cheque::where('estado', 'rechazado')->count()],
        ]);
    }
}
