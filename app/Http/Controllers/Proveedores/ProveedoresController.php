<?php

namespace App\Http\Controllers\Proveedores;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Cheque;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CuentaCorriente;
use App\Models\CuentaFondos;
use App\Models\Pago;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ProveedoresController extends Controller
{
    public function index(Request $request)
    {
        $q = Contact::suppliers()
            ->when($request->buscar, fn($q, $b) => $q->where(fn($w) => $w->where('name', 'like', "%{$b}%")->orWhere('cuit', 'like', "%{$b}%")->orWhere('email', 'like', "%{$b}%")))
            ->when($request->estado === 'deudores', fn($q) => $q->where('balance', '>', 0.005))
            ->when($request->estado === 'inactivos', fn($q) => $q->where('is_active', false))
            ->when(! $request->estado || $request->estado === 'activos', fn($q) => $q->where('is_active', true))
            ->orderBy('name');
        $totales = ['proveedores' => (clone $q)->count(), 'por_pagar' => (float) (clone $q)->where('balance', '>', 0)->sum('balance')];
        $vencido = (float) Comprobante::pendientesPago()->whereDate('fecha_vto', '<', today())->sum('saldo');

        return Inertia::render('Proveedores/Index', [
            'lista' => $q->paginate(30)->withQueryString()->through(fn($c) => ['id' => $c->id, 'name' => $c->name, 'cuit' => $c->cuit, 'condicion_iva' => $c->condicion_iva, 'email' => $c->email, 'phone' => $c->phone ?: $c->mobile, 'city' => $c->city, 'balance' => (float) $c->balance, 'is_active' => $c->is_active, 'dias_pago' => $c->dias_pago]),
            'totales' => $totales + ['vencido' => $vencido], 'filtros' => $request->only('buscar', 'estado'), 'condicionesIva' => Contact::CONDICIONES_IVA,
        ]);
    }

    public function show(int $id)
    {
        $c = Contact::suppliers()->findOrFail($id);
        $movs = CuentaCorriente::where('contact_id', $c->id)->orderBy('fecha')->orderBy('id')->get();
        $saldo = 0;
        $cc = $movs->map(function ($m) use (&$saldo) { $saldo += (float) $m->debe - (float) $m->haber; return ['id' => $m->id, 'fecha' => $m->fecha->format('d/m/Y'), 'fecha_vto' => $m->fecha_vto?->format('d/m/Y'), 'tipo' => $m->tipo, 'concepto' => $m->concepto, 'debe' => (float) $m->debe, 'haber' => (float) $m->haber, 'saldo' => round($saldo, 2), 'comprobante_id' => $m->comprobante_id, 'pago_id' => $m->pago_id]; })->reverse()->values();
        $pendientes = Comprobante::where('contact_id', $c->id)->pendientesPago()->orderBy('fecha_vto')->get()->map(fn($x) => ['id' => $x->id, 'nombre' => $x->nombreTipo(), 'numero' => $x->numeroFormateado(), 'fecha' => $x->fecha->format('d/m/Y'), 'fecha_vto' => $x->fecha_vto?->format('d/m/Y'), 'total' => (float) $x->total, 'saldo' => (float) $x->saldo, 'vencido' => $x->fecha_vto && $x->fecha_vto->lt(today())]);

        return Inertia::render('Proveedores/Ver', [
            'proveedor' => $c->only('id', 'name', 'cuit', 'condicion_iva', 'email', 'phone', 'mobile', 'address', 'city', 'province', 'dias_pago', 'balance', 'is_active', 'notes') + ['deuda_vencida' => $c->deudaVencidaProveedor()],
            'cc' => $cc, 'pendientes' => $pendientes,
            'compras' => Comprobante::compras()->where('contact_id', $c->id)->orderByDesc('fecha')->orderByDesc('id')->limit(30)->get()->map(fn($x) => ['id' => $x->id, 'nombre' => $x->nombreTipo(), 'numero' => $x->numeroFormateado(), 'fecha' => $x->fecha->format('d/m/Y'), 'total' => (float) $x->total, 'saldo' => (float) $x->saldo, 'estado' => $x->estado, 'estado_cobro' => $x->estadoCobro(), 'origen_carga' => $x->origen_carga]),
            'pagos' => Pago::where('contact_id', $c->id)->with('medios')->orderByDesc('fecha')->orderByDesc('id')->limit(20)->get()->map(fn($p) => ['id' => $p->id, 'numero' => $p->numeroFormateado(), 'fecha' => $p->fecha->format('d/m/Y'), 'total' => (float) $p->total, 'a_cuenta' => (float) $p->a_cuenta, 'estado' => $p->estado, 'medios' => $p->medios->map(fn($m) => Pago::MEDIOS[$m->medio] . ' $ ' . number_format((float) $m->monto, 0, ',', '.'))->implode(', ')]),
            'medios' => Pago::MEDIOS, 'condicionesIva' => Contact::CONDICIONES_IVA,
            'cuentas' => CuentaFondos::where('activa', true)->orderBy('tipo')->orderBy('nombre')->get(['id', 'tipo', 'nombre', 'saldo', 'moneda']), 'cotizacionUsd' => \App\Models\Cotizacion::valor($request->user()->business_id),
            'chequesCartera' => Cheque::enCartera()->orderBy('fecha_pago')->get()->map(fn($ch) => ['id' => $ch->id, 'numero' => $ch->numero, 'banco' => $ch->banco, 'emisor' => $ch->emisor, 'fecha_pago' => $ch->fecha_pago?->format('d/m/Y'), 'monto' => (float) $ch->monto]),
            'retencionTipos' => \App\Models\Retencion::TIPOS,
        ]);
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255', 'cuit' => 'nullable|string|max:20', 'condicion_iva' => ['required', Rule::in(Contact::CONDICIONES_IVA)],
            'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:50', 'address' => 'nullable|string|max:255', 'city' => 'nullable|string|max:100', 'province' => 'nullable|string|max:100',
            'dias_pago' => 'nullable|integer|min:0|max:365', 'is_active' => 'boolean', 'notes' => 'nullable|string|max:2000',
        ]);
        $c = $id ? Contact::suppliers()->findOrFail($id) : new Contact(['business_id' => $request->user()->business_id, 'type' => 'supplier']);
        $c->fill($data + ['is_active' => $data['is_active'] ?? true])->save();
        AuditLog::registrar($id ? 'editar' : 'crear', $c, "Proveedor {$c->name}");
        return $id ? back()->with('success', 'Proveedor actualizado.') : redirect("/proveedores/{$c->id}")->with('success', 'Proveedor creado.');
    }
}
