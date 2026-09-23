<?php

namespace App\Http\Controllers\Clientes;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CuentaCorriente;
use App\Models\TipoCliente;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ClientesController extends Controller
{
    public function index(Request $request)
    {
        $q = Contact::customers()->with('tipoCliente:id,nombre,color')
            ->when($request->buscar, fn($q, $b) => $q->where(fn($w) => $w->where('name', 'like', "%{$b}%")->orWhere('cuit', 'like', "%{$b}%")->orWhere('email', 'like', "%{$b}%")->orWhere('phone', 'like', "%{$b}%")))
            ->when($request->tipo_cliente_id, fn($q, $t) => $q->where('tipo_cliente_id', $t))
            ->when($request->estado === 'deudores', fn($q) => $q->where('balance', '>', 0.005))
            ->when($request->estado === 'inactivos', fn($q) => $q->where('is_active', false))
            ->when(! $request->estado || $request->estado === 'activos', fn($q) => $q->where('is_active', true))
            ->orderBy('name');

        $totales = ['clientes' => (clone $q)->count(), 'por_cobrar' => (float) (clone $q)->where('balance', '>', 0)->sum('balance')];
        $lista = $q->paginate(30)->withQueryString()->through(fn($c) => [
            'id' => $c->id, 'name' => $c->name, 'cuit' => $c->cuit, 'condicion_iva' => $c->condicion_iva, 'email' => $c->email, 'phone' => $c->phone ?: $c->mobile,
            'city' => $c->city, 'balance' => (float) $c->balance, 'credit_limit' => (float) $c->credit_limit, 'is_active' => $c->is_active,
            'tipo' => $c->tipoCliente ? ['nombre' => $c->tipoCliente->nombre, 'color' => $c->tipoCliente->color] : null,
        ]);

        return Inertia::render('Clientes/Index', [
            'lista' => $lista, 'totales' => $totales, 'filtros' => $request->only('buscar', 'tipo_cliente_id', 'estado'),
            'tipos' => TipoCliente::withCount('contacts')->orderBy('nombre')->get(),
            'condicionesIva' => Contact::CONDICIONES_IVA,
            'vendedores' => \App\Models\Vendedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function show(int $id)
    {
        $c = Contact::customers()->with('tipoCliente')->findOrFail($id);

        $movimientos = CuentaCorriente::where('contact_id', $c->id)->orderBy('fecha')->orderBy('id')->get();
        $saldo = 0;
        $cc = $movimientos->map(function ($m) use (&$saldo) {
            $saldo += (float) $m->debe - (float) $m->haber;
            return ['id' => $m->id, 'fecha' => $m->fecha->format('d/m/Y'), 'fecha_vto' => $m->fecha_vto?->format('d/m/Y'), 'tipo' => $m->tipo, 'concepto' => $m->concepto, 'debe' => (float) $m->debe, 'haber' => (float) $m->haber, 'saldo' => round($saldo, 2), 'comprobante_id' => $m->comprobante_id, 'cobro_id' => $m->cobro_id];
        })->reverse()->values();

        $pendientes = Comprobante::where('contact_id', $c->id)->pendientesCobro()->orderBy('fecha_vto')->get()
            ->map(fn($x) => ['id' => $x->id, 'nombre' => $x->nombreTipo(), 'numero' => $x->numeroFormateado(), 'fecha' => $x->fecha->format('d/m/Y'), 'fecha_vto' => $x->fecha_vto?->format('d/m/Y'), 'total' => (float) $x->total, 'saldo' => (float) $x->saldo, 'vencido' => $x->vencido()]);

        $antiguedad = ['al_dia' => 0, 'v30' => 0, 'v60' => 0, 'v90' => 0, 'mas90' => 0];
        foreach ($pendientes as $p) {
            $dias = $p['fecha_vto'] ? today()->diffInDays(\Carbon\Carbon::createFromFormat('d/m/Y', $p['fecha_vto']), false) : 0;
            $k = $dias >= 0 ? 'al_dia' : ($dias > -30 ? 'v30' : ($dias > -60 ? 'v60' : ($dias > -90 ? 'v90' : 'mas90')));
            $antiguedad[$k] += $p['saldo'];
        }

        return Inertia::render('Clientes/Ver', [
            'cliente' => $c->only('id', 'name', 'cuit', 'condicion_iva', 'email', 'phone', 'mobile', 'address', 'city', 'province', 'postal_code', 'credit_limit', 'lista_precios', 'dias_pago', 'descuento', 'percepcion_iibb', 'balance', 'is_active', 'notes', 'tipo_cliente_id', 'interes_mora', 'vendedor_id') + ['tipo' => $c->tipoCliente?->nombre, 'deuda_vencida' => $c->deudaVencida(), 'vendedor' => $c->vendedor?->nombre, 'interes_calculado' => $this->interesMora($c, $pendientes)],
            'cc' => $cc, 'pendientes' => $pendientes, 'antiguedad' => $antiguedad,
            'comprobantes' => Comprobante::where('contact_id', $c->id)->orderByDesc('fecha')->orderByDesc('id')->limit(30)->get()->map(fn($x) => ['id' => $x->id, 'nombre' => $x->nombreTipo(), 'numero' => $x->numeroFormateado(), 'fecha' => $x->fecha->format('d/m/Y'), 'total' => (float) $x->total, 'saldo' => (float) $x->saldo, 'estado' => $x->estado, 'estado_cobro' => $x->estadoCobro(), 'es_acopio' => $x->es_acopio]),
            'cobros' => Cobro::where('contact_id', $c->id)->with('medios')->orderByDesc('fecha')->orderByDesc('id')->limit(20)->get()->map(fn($x) => ['id' => $x->id, 'numero' => $x->numeroFormateado(), 'fecha' => $x->fecha->format('d/m/Y'), 'total' => (float) $x->total, 'a_cuenta' => (float) $x->a_cuenta, 'estado' => $x->estado, 'medios' => $x->medios->map(fn($m) => Cobro::MEDIOS[$m->medio] . ' $ ' . number_format((float) $m->monto, 0, ',', '.'))->implode(', ')]),
            'acopios' => $c->acopios()->with('items', 'comprobante')->whereIn('estado', ['abierto', 'parcial', 'vencido'])->get()->map(fn($a) => ['id' => $a->id, 'estado' => $a->estado, 'fecha' => $a->fecha->format('d/m/Y'), 'fecha_limite' => $a->fecha_limite?->format('d/m/Y'), 'factura' => $a->comprobante?->numeroFormateado(), 'comprobante_id' => $a->comprobante_id, 'items' => $a->items->map(fn($i) => ['id' => $i->id, 'descripcion' => $i->descripcion, 'facturada' => (float) $i->cantidad_facturada, 'retirada' => (float) $i->cantidad_retirada, 'pendiente' => $i->pendiente()])]),
            'medios' => Cobro::MEDIOS, 'tipos' => TipoCliente::orderBy('nombre')->get(['id', 'nombre']), 'condicionesIva' => Contact::CONDICIONES_IVA,
            'cuentas' => \App\Models\CuentaFondos::where('activa', true)->orderBy('tipo')->orderBy('nombre')->get(['id', 'tipo', 'nombre', 'saldo']),
            'vendedores' => \App\Models\Vendedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function pendientesJson(int $id)
    {
        return response()->json(Comprobante::where('contact_id', $id)->pendientesCobro()->orderBy('fecha_vto')->get()->map(fn($x) => ['id' => $x->id, 'nombre' => $x->nombreTipo(), 'numero' => $x->numeroFormateado(), 'fecha_vto' => $x->fecha_vto?->format('d/m/Y'), 'saldo' => (float) $x->saldo, 'vencido' => $x->vencido()]));
    }

    // Interés por mora sugerido: % mensual del cliente, prorrateado por día sobre cada saldo vencido.
    private function interesMora(Contact $c, $pendientes): float
    {
        if ((float) $c->interes_mora <= 0) return 0;
        $total = 0;
        foreach ($pendientes as $p) {
            if (! $p['vencido'] || ! $p['fecha_vto']) continue;
            $dias = \Carbon\Carbon::createFromFormat('d/m/Y', $p['fecha_vto'])->diffInDays(today());
            $total += $p['saldo'] * (float) $c->interes_mora / 100 / 30 * $dias;
        }
        return round($total, 2);
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $b = $request->user()->business_id;
        $data = $request->validate([
            'name' => 'required|string|max:255', 'cuit' => 'nullable|string|max:20', 'condicion_iva' => ['required', Rule::in(Contact::CONDICIONES_IVA)],
            'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:50', 'mobile' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255', 'city' => 'nullable|string|max:100', 'province' => 'nullable|string|max:100', 'postal_code' => 'nullable|string|max:20',
            'tipo_cliente_id' => ['nullable', Rule::exists('tipos_cliente', 'id')->where('business_id', $b)],
            'credit_limit' => 'nullable|numeric|min:0', 'lista_precios' => 'nullable|integer|min:1|max:5', 'dias_pago' => 'nullable|integer|min:0|max:365', 'interes_mora' => 'nullable|numeric|min:0|max:100', 'vendedor_id' => 'nullable|exists:vendedores,id',
            'descuento' => 'nullable|numeric|min:0|max:100', 'percepcion_iibb' => 'boolean', 'is_active' => 'boolean', 'notes' => 'nullable|string|max:2000',
        ]);
        if (($data['condicion_iva'] === 'Responsable Inscripto' || $data['condicion_iva'] === 'Monotributista') && empty($data['cuit'])) {
            return back()->withErrors(['cuit' => 'Un responsable inscripto o monotributista necesita CUIT para facturar.']);
        }

        $c = $id ? Contact::customers()->findOrFail($id) : new Contact(['business_id' => $b, 'type' => 'customer']);
        if (! $id && ! empty($data['tipo_cliente_id'])) {
            $t = TipoCliente::find($data['tipo_cliente_id']);
            $data['lista_precios'] = $data['lista_precios'] ?? $t->lista_precios;
            $data['dias_pago'] = $data['dias_pago'] ?? $t->dias_pago;
            $data['descuento'] = $data['descuento'] ?? $t->descuento;
            $data['credit_limit'] = $data['credit_limit'] ?? $t->limite_credito;
        }
        $c->fill($data + ['is_active' => $data['is_active'] ?? true])->save();
        AuditLog::registrar($id ? 'editar' : 'crear', $c, "Cliente {$c->name}");
        return $id ? back()->with('success', 'Cliente actualizado.') : redirect("/clientes/{$c->id}")->with('success', 'Cliente creado.');
    }

    public function guardarTipo(Request $request, ?int $id = null)
    {
        $data = $request->validate(['nombre' => 'required|string|max:60', 'lista_precios' => 'required|integer|min:1|max:5', 'dias_pago' => 'required|integer|min:0|max:365', 'descuento' => 'required|numeric|min:0|max:100', 'limite_credito' => 'required|numeric|min:0', 'color' => 'nullable|string|max:10']);
        $t = $id ? TipoCliente::findOrFail($id) : new TipoCliente(['business_id' => $request->user()->business_id]);
        $t->fill($data)->save();
        return back()->with('success', $id ? 'Tipo de cliente actualizado.' : 'Tipo de cliente creado.');
    }

    public function eliminarTipo(int $id)
    {
        $t = TipoCliente::findOrFail($id);
        if ($t->contacts()->exists()) {
            return back()->with('error', 'Hay clientes con este tipo. Reasignalos antes de borrarlo.');
        }
        $t->delete();
        return back()->with('success', 'Tipo eliminado.');
    }
}
