<?php

namespace App\Http\Controllers\Comprobantes;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\PosController;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\Vendedor;
use App\Models\Visita;
use App\Services\Ventas\CobranzaCalleService;
use App\Services\Ventas\EquipoVentasService;
use App\Services\Ventas\VenderService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 29: Comprobantes → Vender. Una sola pantalla con tres modos: calle (ruta, cliente, pedido), mostrador y caja (el POS).
// /retail y /minimarket siguen entrando acá, en modo caja.
class VenderController extends Controller
{
    public function __construct(private VenderService $vender, private PosController $pos, private CobranzaCalleService $calle, private EquipoVentasService $equipo) {}

    public function index(Request $request)
    {
        $u = $request->user(); $v = Vendedor::deUsuario($u->id);
        $modo = $request->query('modo') ?: (str_contains($request->path(), 'retail') || str_contains($request->path(), 'minimarket') ? 'caja' : ($v?->modo_default ?? 'mostrador'));
        if (! isset(VenderService::MODOS[$modo])) $modo = 'mostrador';
        // Lo del POS (catálogo chico, cuentas, caja, cuotas, Mercado Pago) se arma igual para los tres modos.
        $base = $this->pos->datos($request);
        return Inertia::render('Comprobantes/Vender', $base + [
            'modo' => $modo, 'modos' => VenderService::MODOS,
            'vendedor' => $v ? ['id' => $v->id, 'nombre' => $v->nombre, 'modo_default' => $v->modo_default] : null,
            'encargado' => $u->puede('clientes', 'editar'),
            'config' => $this->vender->config($u->business),
            'puede' => ['facturar' => $u->puede('comprobantes', 'crear'), 'precios' => $u->puede('comprobantes', 'editar'), 'cobrar' => $u->puede('clientes', 'crear')],
            'motivos' => Visita::MOTIVOS, 'motivosDevolucion' => CobranzaCalleService::MOTIVOS_DEVOLUCION, 'bancos' => \App\Support\Bancos::LISTA, 'tiposRelevamiento' => \App\Models\Relevamiento::TIPOS, 'avisos' => $this->equipo->avisosPara($v),
        ]);
    }

    // El paquete completo para trabajar sin señal (clientes de la ruta y catálogo con las seis listas).
    public function paquete(Request $request)
    {
        return response()->json($this->vender->paquete($request->user(), Vendedor::deUsuario($request->user()->id)));
    }

    public function ruta(Request $request)
    {
        return response()->json($this->vender->ruta($request->user(), Vendedor::deUsuario($request->user()->id), $request->query('q')));
    }

    public function ficha(int $id)
    {
        return response()->json($this->vender->ficha(Contact::customers()->findOrFail($id)));
    }

    public function visita(Request $request, int $id)
    {
        $d = $request->validate(['tipo' => 'required|in:llegue,no_vendi', 'motivo' => 'nullable|in:' . implode(',', array_keys(Visita::MOTIVOS)), 'nota' => 'nullable|string|max:300', 'lat' => 'nullable|numeric', 'lng' => 'nullable|numeric', 'cuando' => 'nullable|date', 'offline_id' => 'nullable|string|max:64']);
        $c = Contact::customers()->findOrFail($id);
        // Una visita hecha sin señal que se reintenta no se registra dos veces.
        if (! empty($d['offline_id']) && Visita::where('contact_id', $c->id)->where('nota', 'like', "%[{$d['offline_id']}]%")->exists()) return response()->json(['ok' => true, 'repetida' => true]);
        if (! empty($d['offline_id'])) $d['nota'] = trim(($d['nota'] ?? '') . " [{$d['offline_id']}]");
        $v = $this->vender->visita($request->user(), $c, $d);
        return response()->json(['ok' => true, 'id' => $v->id]);
    }

    // El encargado pone su usuario y clave en el teléfono del vendedor y la app recibe un OK que vale 15 minutos y una venta.
    public function autorizar(Request $request)
    {
        $d = $request->validate(['contact_id' => 'required|exists:contacts,id', 'pct' => 'required|numeric|min:0|max:100', 'email' => 'required|email', 'password' => 'required|string']);
        $a = $this->vender->autorizar($request->user(), Contact::customers()->findOrFail($d['contact_id']), (float) $d['pct'], $d['email'], $d['password']);
        return response()->json(['ok' => true, 'token' => $a->token, 'pct' => $a->pct, 'encargado' => $a->encargado->name, 'vence' => $a->vence_at->toIso8601String()]);
    }

    // ---- Fase 29.6: mi reporte, avisos, notas del cliente y relevamiento ----
    public function reporte(Request $request) { return response()->json($this->equipo->reporteVendedor($request->user(), $request->query('periodo'))); }

    public function avisos(Request $request) { return response()->json($this->equipo->avisosPara(Vendedor::deUsuario($request->user()->id))); }

    public function notas(int $id) { return response()->json($this->equipo->notas(Contact::customers()->findOrFail($id))); }

    public function anotar(Request $request, int $id)
    {
        $d = $request->validate(['texto' => 'required|string|max:500', 'offline_id' => 'nullable|string|max:64']);
        $c = Contact::customers()->findOrFail($id);
        if (! empty($d['offline_id']) && \App\Models\NotaCliente::where('contact_id', $c->id)->where('texto', 'like', "%[{$d['offline_id']}]%")->exists()) return response()->json(['ok' => true, 'repetida' => true]);
        $n = $this->equipo->anotar($request->user(), $c, $d['texto'] . (! empty($d['offline_id']) ? " [{$d['offline_id']}]" : ''));
        return response()->json(['ok' => true, 'id' => $n->id, 'notas' => $this->equipo->notas($c)]);
    }

    public function notaLista(Request $request, int $id, int $nota)
    {
        $c = Contact::customers()->findOrFail($id);
        \App\Models\NotaCliente::where('contact_id', $c->id)->findOrFail($nota)->forceFill(['listo_at' => now(), 'listo_por' => $request->user()->id])->save();
        return response()->json(['ok' => true, 'notas' => $this->equipo->notas($c)]);
    }

    public function relevar(Request $request, int $id)
    {
        $d = $request->validate(['tipo' => 'required|in:' . implode(',', array_keys(\App\Models\Relevamiento::TIPOS)), 'nota' => 'nullable|string|max:300', 'foto' => 'required|string|max:4500000', 'lat' => 'nullable|numeric', 'lng' => 'nullable|numeric']);
        $r = $this->equipo->relevar($request->user(), Contact::customers()->findOrFail($id), $d);
        return response()->json(['ok' => true, 'id' => $r->id]);
    }

    // ---- Fase 29.5: cobrar, rendir, devolver y confirmar la entrega ----
    public function hoy(Request $request) { return response()->json($this->calle->hoy($request->user())); }

    public function cobrar(Request $request, int $id)
    {
        $d = $request->validate(['medios' => 'required|array|min:1', 'medios.*.medio' => 'required|in:efectivo,transferencia,cheque', 'medios.*.monto' => 'required|numeric|min:0', 'medios.*.referencia' => 'nullable|string|max:120', 'medios.*.datos' => 'nullable|array', 'medios.*.datos.numero' => 'nullable|string|max:40', 'medios.*.datos.banco' => 'nullable|string|max:80', 'medios.*.datos.fecha_pago' => 'nullable|date', 'medios.*.datos.titular' => 'nullable|string|max:120', 'medios.*.datos.echeq' => 'nullable|boolean',
            'firma' => 'nullable|string|max:300000', 'firma_nombre' => 'nullable|string|max:120', 'notas' => 'nullable|string|max:300', 'forzar_cheque' => 'boolean', 'lat' => 'nullable|numeric', 'lng' => 'nullable|numeric', 'offline_id' => 'nullable|string|max:64', 'fecha_offline' => 'nullable|date']);
        $c = Contact::customers()->findOrFail($id);
        if (! empty($d['offline_id']) && ($ya = \App\Models\Cobro::where('contact_id', $c->id)->where('notas', 'like', "%[{$d['offline_id']}]%")->first())) return response()->json(['ok' => true, 'repetida' => true, 'cobro_id' => $ya->id, 'numero' => $ya->numeroFormateado(), 'total' => (float) $ya->total]);
        if (! empty($d['offline_id'])) $d['notas'] = trim(($d['notas'] ?? '') . " [{$d['offline_id']}]");
        $co = $this->calle->cobrar($request->user(), $c, $d);
        return response()->json(['ok' => true, 'cobro_id' => $co->id, 'numero' => $co->numeroFormateado(), 'total' => (float) $co->total, 'a_cuenta' => (float) $co->a_cuenta, 'saldo' => (float) $c->fresh()->balance]);
    }

    public function rendir(Request $request)
    {
        $d = $request->validate(['notas' => 'nullable|string|max:300']);
        $r = $this->calle->rendir($request->user(), $d['notas'] ?? null);
        return response()->json(['ok' => true, 'numero' => $r->numeroFormateado(), 'efectivo' => $r->efectivo, 'transferencias' => $r->transferencias, 'cheques' => $r->cheques, 'recibos' => $r->recibos]);
    }

    public function devolucion(Request $request, int $id)
    {
        $d = $request->validate(['items' => 'required|array|min:1', 'items.*.product_id' => 'required|exists:products,id', 'items.*.cantidad' => 'required|numeric|min:0.001', 'motivo' => 'required|in:' . implode(',', array_keys(CobranzaCalleService::MOTIVOS_DEVOLUCION)), 'nota' => 'nullable|string|max:300', 'lat' => 'nullable|numeric', 'lng' => 'nullable|numeric', 'offline_id' => 'nullable|string|max:64', 'fecha_offline' => 'nullable|date']);
        $c = Contact::customers()->findOrFail($id);
        if (! empty($d['offline_id']) && ($ya = Comprobante::ventas()->where('offline_id', $d['offline_id'])->first())) return response()->json(['ok' => true, 'repetida' => true, 'comprobante_id' => $ya->id]);
        $nc = $this->calle->devolucion($request->user(), $c, $d);
        if (! empty($d['offline_id'])) $nc->forceFill(['offline_id' => $d['offline_id']])->save();
        return response()->json(['ok' => true, 'comprobante_id' => $nc->id, 'tipo' => $nc->nombreTipo(), 'estado' => $nc->estado, 'numero' => $nc->estado === 'emitido' ? $nc->numeroFormateado() : "#{$nc->id}", 'total' => (float) $nc->total]);
    }

    public function entrega(Request $request, int $id)
    {
        $d = $request->validate(['nombre' => 'required|string|max:120', 'firma' => 'nullable|string|max:300000', 'foto' => 'nullable|string|max:6000000', 'cuando' => 'nullable|date']);
        $c = Comprobante::ventas()->findOrFail($id);
        $c = $this->calle->confirmarEntrega($c, $request->user(), $d);
        return response()->json(['ok' => true, 'entregado' => ['nombre' => $c->entrega_nombre, 'hora' => $c->entregado_at->format('H:i')]]);
    }

    public function fotoEntrega(int $id)
    {
        $c = Comprobante::ventas()->findOrFail($id);
        abort_unless($c->entrega_foto && \Illuminate\Support\Facades\Storage::disk('local')->exists($c->entrega_foto), 404);
        return \Illuminate\Support\Facades\Storage::disk('local')->response($c->entrega_foto);
    }

    public function vender(Request $request)
    {
        $d = $request->validate([
            'modo' => 'nullable|in:calle,mostrador,caja', 'tipo' => 'nullable|in:PRE,FX,REM', 'contact_id' => 'nullable|exists:contacts,id', 'notas' => 'nullable|string|max:300', 'a_cuenta' => 'boolean', 'precios_con_iva' => 'boolean', 'forzar' => 'boolean',
            'items' => 'required|array|min:1', 'items.*.product_id' => 'nullable|exists:products,id', 'items.*.serie' => 'nullable|string|max:2000', 'items.*.descripcion' => 'nullable|string|max:150', 'items.*.cantidad' => 'required|numeric|min:0.001', 'items.*.precio_unit' => 'required|numeric|min:0', 'items.*.descuento' => 'nullable|numeric|min:0|max:100', 'items.*.autorizado' => 'nullable|string|max:80',
            'medios' => 'nullable|array', 'medios.*.medio' => 'required|in:' . implode(',', array_keys(Cobro::MEDIOS)), 'medios.*.monto' => 'required|numeric|min:0', 'medios.*.cuenta_fondos_id' => 'nullable|integer', 'medios.*.referencia' => 'nullable|string|max:120', 'medios.*.datos' => 'nullable|array', 'medios.*.datos.tarjeta' => 'nullable|string|max:60', 'medios.*.datos.cuotas' => 'nullable|integer|min:1|max:60',
            'lat' => 'nullable|numeric', 'lng' => 'nullable|numeric', 'offline_id' => 'nullable|string|max:64', 'fecha_offline' => 'nullable|date',
        ]);
        if (! empty($d['offline_id']) && ($ya = Comprobante::ventas()->where('offline_id', $d['offline_id'])->first())) {
            return response()->json(['ok' => true, 'repetida' => true, 'comprobante_id' => $ya->id, 'numero' => $ya->estado === 'emitido' ? $ya->numeroFormateado() : "#{$ya->id}", 'total' => (float) $ya->total]);
        }
        $d['modo'] = $d['modo'] ?? 'caja';
        $r = $this->vender->vender($request->user(), $d);
        $c = $r['comprobante'];
        if (! empty($d['offline_id']) && $d['modo'] !== 'calle') $c->forceFill(['offline_id' => $d['offline_id'], 'notas' => trim(($c->notas ?? '') . ' · Venta sin conexión del ' . \Carbon\Carbon::parse($d['fecha_offline'] ?? now())->format('d/m H:i'))])->save();
        $out = ['ok' => true, 'comprobante_id' => $c->id, 'tipo' => $c->nombreTipo(), 'estado' => $c->estado, 'numero' => $c->estado === 'emitido' ? $c->numeroFormateado() : "#{$c->id}", 'total' => (float) $c->total, 'vuelto' => $r['vuelto'], 'presupuesto' => $r['presupuesto'], 'aviso' => $r['aviso'] ?? null];
        if ($request->wantsJson()) return response()->json($out);
        return back()->with('success', "{$c->nombreTipo()} {$out['numero']} · " . number_format((float) $c->total, 2, ',', '.') . ($r['vuelto'] > 0 ? ' · vuelto $ ' . number_format($r['vuelto'], 2, ',', '.') : ''))->with('pos', $out);
    }
}
