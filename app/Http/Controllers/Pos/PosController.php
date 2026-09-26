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
    public const LIMITE_POS = 400;

    public function index(Request $request, PosService $pos)
    {
        $user = $request->user();
        $vertical = str_contains($request->path(), 'minimarket') ? 'minimarket' : 'retail';
        $ri = ($user->business->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        $caja = CuentaFondos::where('activa', true)->where('tipo', 'caja')->where('business_location_id', $user->current_location_id)->orderByDesc('es_default')->first() ?? CuentaFondos::where('activa', true)->where('tipo', 'caja')->first();
        $hoy = Comprobante::ventas()->emitidos()->facturas()->where('condicion', 'contado')->where('fecha', today()->toDateString())->where('user_id', $user->id);

        // Catálogos grandes: van los favoritos y los primeros por nombre; el resto se busca en el servidor al escribir.
        // Catálogo del POS: chico para que abra en menos de un segundo (favoritos + los más vendidos de los últimos 90 días); el resto se busca al escribir o con el lector.
        $q = Product::where('active', true)->where('tipo', '!=', 'insumo');
        $parcial = $q->clone()->count() > self::LIMITE_POS;
        $productos = $q->with('rubro:id,nombre,color')
            ->when($parcial, function ($qq) use ($user) {
                $top = \Illuminate\Support\Facades\DB::table('comprobante_items')->join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->where('comprobantes.business_id', $user->business_id)->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')->where('comprobantes.fecha', '>=', now()->subDays(90)->toDateString())->whereNotNull('comprobante_items.product_id')->selectRaw('comprobante_items.product_id, SUM(comprobante_items.cantidad) as c')->groupBy('comprobante_items.product_id')->orderByDesc('c')->limit(self::LIMITE_POS)->pluck('product_id')->all();
                $qq->where(fn($w) => $w->where('favorito_pos', true)->orWhereIn('id', $top));
            })
            ->orderByDesc('favorito_pos')->orderBy('name')->limit(self::LIMITE_POS)->get()->map(fn($p) => $this->fila($p, $ri))->values();

        return Inertia::render('Pos/Index', [
            'vertical' => $vertical,
            'productos' => $productos, 'catalogoParcial' => $parcial,
            'rubros' => Rubro::orderBy('orden')->orderBy('nombre')->get(['id', 'nombre', 'color', 'parent_id']),
            'clientes' => Contact::customers()->where('is_active', true)->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$pos->consumidorFinal()->id])->orderBy('name')->limit(300)->get(['id', 'name', 'cuit', 'condicion_iva', 'lista_precios', 'descuento', 'balance', 'credit_limit']),
            'consumidorFinalId' => $pos->consumidorFinal()->id,
            'cuentas' => CuentaFondos::where('activa', true)->orderBy('tipo')->get(['id', 'tipo', 'nombre']),
            'caja' => $caja ? ['id' => $caja->id, 'nombre' => $caja->nombre, 'saldo' => (float) $caja->saldo, 'turno' => $caja->turnoAbierto ? ['id' => $caja->turnoAbierto->id, 'desde' => $caja->turnoAbierto->apertura->format('H:i'), 'usuario' => $caja->turnoAbierto->user?->name] : null] : null,
            'hoy' => ['ventas' => (float) (clone $hoy)->sum('total'), 'tickets' => (clone $hoy)->count(), 'ultimos' => (clone $hoy)->latest('id')->limit(8)->get()->map(fn($c) => ['id' => $c->id, 'numero' => $c->numeroFormateado(), 'total' => (float) $c->total, 'hora' => $c->emitido_en?->format('H:i'), 'cliente' => $c->contact?->name])],
            'empresaLetra' => $ri ? 'B' : 'C', 'preciosConIva' => $ri,
            'planesCuotas' => app(\App\Services\Pos\CuotasService::class)->planes($user->business),
            'mp' => ['qr' => app(\App\Services\MercadoPago\MercadoPagoCobrosService::class)->qrConfigurado($user->business), 'point' => app(\App\Services\MercadoPago\MercadoPagoCobrosService::class)->pointConfigurado($user->business)],
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

    // Lectura de la etiqueta de serie de una unidad: devuelve el artículo y esa serie si está en stock (Fase 27.2).
    public function serie(Request $request)
    {
        $l = \App\Models\Lote::with('product.rubro')->where('serie', trim((string) $request->codigo))->where('cantidad', '>', 0)->where('estado', 'disponible')->first();
        if (! $l || ! $l->product) return response()->json(['error' => 'No hay ninguna unidad en stock con esa serie.'], 404);
        $ri = ($request->user()->business->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        return response()->json(['producto' => $this->fila($l->product, $ri), 'serie' => $l->serie]);
    }

    private function fila(Product $p, bool $ri): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode, 'unit' => $p->unit, 'price' => $this->final($p, (float) $p->price, $ri), 'prices' => collect([1, 2, 3, 4, 5, 6])->mapWithKeys(fn($l) => [$l => $this->final($p, $p->precioLista($l), $ri)])->all(), 'iva' => (float) $p->iva, 'stock' => (float) $p->stock, 'controla' => $p->controla_stock, 'rubro_id' => $p->rubro_id, 'rubro' => $p->rubro?->nombre, 'color' => $p->rubro?->color, 'favorito' => $p->favorito_pos, 'seriado' => (bool) $p->seriado];
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
            'items' => 'required|array|min:1', 'items.*.product_id' => 'nullable|exists:products,id', 'items.*.serie' => 'nullable|string|max:2000', 'items.*.descripcion' => 'nullable|string|max:150', 'items.*.cantidad' => 'required|numeric|min:0.001', 'items.*.precio_unit' => 'required|numeric|min:0', 'items.*.descuento' => 'nullable|numeric|min:0|max:100',
            'medios' => 'nullable|array', 'medios.*.medio' => 'required|in:' . implode(',', array_keys(Cobro::MEDIOS)), 'medios.*.monto' => 'required|numeric|min:0', 'medios.*.cuenta_fondos_id' => 'nullable|integer', 'medios.*.referencia' => 'nullable|string|max:120', 'medios.*.datos' => 'nullable|array', 'medios.*.datos.tarjeta' => 'nullable|string|max:60', 'medios.*.datos.cuotas' => 'nullable|integer|min:1|max:60',
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

    // Mercado Pago presencial: QR de mostrador o Point. Se inicia el cobro, el POS consulta el estado y al aprobarse emite la venta con la referencia del pago.
    public function mpIniciar(Request $request, \App\Services\MercadoPago\MercadoPagoCobrosService $mp)
    {
        $d = $request->validate(['tipo' => 'required|in:qr,point', 'monto' => 'required|numeric|min:1']);
        $b = $request->user()->business;
        $ref = 'pos-' . $b->id . '-' . now()->format('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
        try {
            $r = $d['tipo'] === 'qr' ? $mp->iniciarQr($b, (float) $d['monto'], $ref) : $mp->iniciarPoint($b, (float) $d['monto'], $ref);
        } catch (\RuntimeException $e) { return response()->json(['error' => $e->getMessage()], 422); }
        catch (\Illuminate\Http\Client\ConnectionException $e) { return response()->json(['error' => 'No se pudo conectar con Mercado Pago. Revisá la conexión a internet y volvé a intentar.'], 422); }
        return response()->json($r);
    }

    public function mpEstado(Request $request, \App\Services\MercadoPago\MercadoPagoCobrosService $mp)
    {
        $d = $request->validate(['tipo' => 'required|in:qr,point', 'id' => 'required|string|max:80']);
        $b = $request->user()->business;
        try { return response()->json($d['tipo'] === 'qr' ? $mp->estadoQr($b, $d['id']) : $mp->estadoPoint($b, $d['id'])); }
        catch (\Throwable $e) { return response()->json(['estado' => 'pendiente', 'error' => $e->getMessage()]); }
    }

    public function mpCancelar(Request $request, \App\Services\MercadoPago\MercadoPagoCobrosService $mp)
    {
        $d = $request->validate(['tipo' => 'required|in:qr,point', 'id' => 'required|string|max:80']);
        try { if ($d['tipo'] === 'point') $mp->cancelarPoint($request->user()->business, $d['id']); } catch (\Throwable $e) {}
        return response()->json(['ok' => true]);
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
        return view('comprobantes.ticket', ['c' => $c, 'b' => $c->emisor(), 'cobro' => $cobro, 'vuelto' => (float) $request->query('vuelto', 0)]);
    }
}
