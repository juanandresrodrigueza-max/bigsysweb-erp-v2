<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\Product;
use App\Models\Rubro;
use App\Services\Pos\PosService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Punto de venta rápido (comercio y minimarket): buscar o escanear, cobrar, ticket.
class PosController extends Controller
{
    public function index(Request $request, PosService $pos)
    {
        $user = $request->user();
        $vertical = str_contains($request->path(), 'minimarket') ? 'minimarket' : 'retail';
        $ri = ($user->business->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        $caja = CuentaFondos::where('activa', true)->where('tipo', 'caja')->where('business_location_id', $user->current_location_id)->orderByDesc('es_default')->first() ?? CuentaFondos::where('activa', true)->where('tipo', 'caja')->first();
        $hoy = Comprobante::ventas()->emitidos()->facturas()->where('condicion', 'contado')->where('fecha', today()->toDateString())->where('user_id', $user->id);

        // Catálogos grandes: van los favoritos y los primeros por nombre; el resto se busca en el servidor al escribir.
        $q = Product::where('active', true)->where('tipo', '!=', 'insumo');
        $parcial = $q->clone()->count() > \App\Support\Catalogo::LIMITE;
        $productos = $q->with('rubro:id,nombre,color')->orderByDesc('favorito_pos')->orderBy('name')->limit(\App\Support\Catalogo::LIMITE)->get()->map(fn($p) => $this->fila($p, $ri))->values();

        return Inertia::render('Pos/Index', [
            'vertical' => $vertical,
            'productos' => $productos, 'catalogoParcial' => $parcial,
            'rubros' => Rubro::orderBy('orden')->orderBy('nombre')->get(['id', 'nombre', 'color', 'parent_id']),
            'clientes' => Contact::customers()->where('is_active', true)->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$pos->consumidorFinal()->id])->orderBy('name')->limit(\App\Support\Catalogo::LIMITE)->get(['id', 'name', 'cuit', 'condicion_iva', 'lista_precios', 'descuento', 'balance', 'credit_limit']),
            'consumidorFinalId' => $pos->consumidorFinal()->id,
            'cuentas' => CuentaFondos::where('activa', true)->orderBy('tipo')->get(['id', 'tipo', 'nombre']),
            'caja' => $caja ? ['id' => $caja->id, 'nombre' => $caja->nombre, 'saldo' => (float) $caja->saldo, 'turno' => $caja->turnoAbierto ? ['id' => $caja->turnoAbierto->id, 'desde' => $caja->turnoAbierto->apertura->format('H:i'), 'usuario' => $caja->turnoAbierto->user?->name] : null] : null,
            'hoy' => ['ventas' => (float) (clone $hoy)->sum('total'), 'tickets' => (clone $hoy)->count(), 'ultimos' => (clone $hoy)->latest('id')->limit(8)->get()->map(fn($c) => ['id' => $c->id, 'numero' => $c->numeroFormateado(), 'total' => (float) $c->total, 'hora' => $c->emitido_en?->format('H:i'), 'cliente' => $c->contact?->name])],
            'empresaLetra' => $ri ? 'B' : 'C', 'preciosConIva' => $ri,
            'posConfig' => array_replace(['balanza_prefijo' => '2', 'balanza_modo' => 'peso', 'balanza_decimales' => 3, 'imprimir_auto' => false, 'impresora' => 'navegador', 'ancho' => 42], $user->business->pos ?? []),
        ]);
    }

    // Búsqueda en el servidor para catálogos grandes (nombre, código o barras).
    public function buscar(Request $request)
    {
        $ri = ($request->user()->business->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        $t = '%' . trim((string) $request->q) . '%'; $like = \App\Support\Sql::like();
        return response()->json(Product::where('active', true)->where('tipo', '!=', 'insumo')->with('rubro:id,nombre,color')
            ->where(fn($w) => $w->where('name', $like, $t)->orWhere('sku', $like, $t)->orWhere('barcode', $like, $t))->orderBy('name')->limit(40)->get()->map(fn($p) => $this->fila($p, $ri))->values());
    }

    private function fila(Product $p, bool $ri): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode, 'unit' => $p->unit, 'price' => $this->final($p, (float) $p->price, $ri), 'prices' => collect([1, 2, 3, 4, 5, 6])->mapWithKeys(fn($l) => [$l => $this->final($p, $p->precioLista($l), $ri)])->all(), 'iva' => (float) $p->iva, 'stock' => (float) $p->stock, 'controla' => $p->controla_stock, 'rubro_id' => $p->rubro_id, 'rubro' => $p->rubro?->nombre, 'color' => $p->rubro?->color, 'favorito' => $p->favorito_pos];
    }

    // Precio final que ve el cajero: IVA incluido cuando la empresa es RI (el comprobante guarda el neto).
    private function final(Product $p, float $precio, bool $ri): float
    {
        return round($precio * ($ri ? 1 + (float) $p->iva / 100 : 1), 2);
    }

    public function vender(Request $request, PosService $pos)
    {
        $d = $request->validate([
            'contact_id' => 'nullable|exists:contacts,id', 'notas' => 'nullable|string|max:200', 'a_cuenta' => 'boolean', 'precios_con_iva' => 'boolean',
            'items' => 'required|array|min:1', 'items.*.product_id' => 'nullable|exists:products,id', 'items.*.descripcion' => 'nullable|string|max:150', 'items.*.cantidad' => 'required|numeric|min:0.001', 'items.*.precio_unit' => 'required|numeric|min:0', 'items.*.descuento' => 'nullable|numeric|min:0|max:100',
            'medios' => 'nullable|array', 'medios.*.medio' => 'required|in:' . implode(',', array_keys(Cobro::MEDIOS)), 'medios.*.monto' => 'required|numeric|min:0', 'medios.*.cuenta_fondos_id' => 'nullable|integer', 'medios.*.referencia' => 'nullable|string|max:120',
            'offline_id' => 'nullable|string|max:64', 'fecha_offline' => 'nullable|date',
        ]);
        // Venta hecha sin conexión: si ya se sincronizó, se devuelve la misma (idempotente).
        if (! empty($d['offline_id']) && ($ya = Comprobante::ventas()->where('offline_id', $d['offline_id'])->first())) {
            return $request->wantsJson() ? response()->json(['ok' => true, 'repetida' => true, 'comprobante_id' => $ya->id, 'numero' => $ya->numeroFormateado()]) : back()->with('success', 'Esa venta ya estaba sincronizada.');
        }
        $r = $pos->vender($d);
        $c = $r['comprobante'];
        if (! empty($d['offline_id'])) $c->forceFill(['offline_id' => $d['offline_id'], 'notas' => trim(($c->notas ?? '') . ' · Venta sin conexión del ' . \Carbon\Carbon::parse($d['fecha_offline'] ?? now())->format('d/m H:i'))])->save();
        if ($request->wantsJson()) return response()->json(['ok' => true, 'comprobante_id' => $c->id, 'numero' => $c->numeroFormateado(), 'total' => (float) $c->total, 'vuelto' => $r['vuelto']]);
        return back()->with('success', "{$c->nombreTipo()} {$c->numeroFormateado()} · " . number_format((float) $c->total, 2, ',', '.') . ($r['vuelto'] > 0 ? ' · vuelto $ ' . number_format($r['vuelto'], 2, ',', '.') : ''))->with('pos', ['comprobante_id' => $c->id, 'numero' => $c->numeroFormateado(), 'total' => (float) $c->total, 'vuelto' => $r['vuelto']]);
    }

    // Bytes ESC/POS para impresora térmica (WebSerial/WebUSB desde el navegador o agente local).
    public function escpos(int $id, Request $request, \App\Services\Pos\EscPosService $esc)
    {
        $c = Comprobante::ventas()->with(['items', 'contact', 'business', 'user', 'imputaciones.cobro.medios'])->findOrFail($id);
        $ancho = (int) ($request->user()->business->pos['ancho'] ?? 42);
        return response($esc->ticket($c, $ancho ?: 42), 200, ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => "attachment; filename=ticket_{$c->id}.bin"]);
    }

    public function ticket(int $id, Request $request)
    {
        $c = Comprobante::ventas()->with(['items', 'contact', 'business', 'location', 'user', 'imputaciones.cobro.medios'])->findOrFail($id);
        $cobro = $c->imputaciones->first()?->cobro;
        return view('comprobantes.ticket', ['c' => $c, 'b' => $c->business, 'cobro' => $cobro, 'vuelto' => (float) $request->query('vuelto', 0)]);
    }
}
