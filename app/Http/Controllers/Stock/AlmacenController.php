<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Models\Deposito;
use App\Models\Product;
use App\Models\Ubicacion;
use App\Models\UbicacionMovimiento;
use App\Models\UbicacionStock;
use App\Services\Stock\AlmacenService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// Fase 27.4: gestión de almacén: ubicaciones, pistola (guardar, mover, consultar, contar), preparación de pedidos y etiquetas.
class AlmacenController extends Controller
{
    public function __construct(private AlmacenService $svc) {}

    private function deposito(Request $request): ?Deposito
    {
        return $request->deposito ? Deposito::findOrFail($request->deposito) : Deposito::porDefecto($request->user()->current_location_id);
    }

    public function index(Request $request)
    {
        $dep = $this->deposito($request);
        $ubic = Ubicacion::where('deposito_id', $dep?->id)->withCount('stocks')->withSum('stocks as unidades', 'cantidad')->orderBy('orden')->orderBy('codigo')->get();
        $sinUbicar = $dep ? \App\Models\StockDeposito::with('product:id,name,sku,unit,controla_stock')->where('deposito_id', $dep->id)->where('cantidad', '>', 0)->get()
            ->map(fn($sd) => ['product_id' => $sd->product_id, 'nombre' => $sd->product?->name, 'sku' => $sd->product?->sku, 'unidad' => $sd->product?->unit, 'cantidad' => $sd->product ? $this->svc->sinUbicar($sd->product, $dep) : 0])
            ->filter(fn($x) => $x['cantidad'] > 0.0005)->sortByDesc('cantidad')->values()->take(200) : collect();
        return Inertia::render('Stock/Almacen', [
            'deposito' => $dep?->only('id', 'nombre'), 'depositos' => Deposito::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'ubicaciones' => $ubic->map(fn($u) => ['id' => $u->id, 'codigo' => $u->codigo, 'pasillo' => $u->pasillo, 'estante' => $u->estante, 'nivel' => $u->nivel, 'tipo' => $u->tipo, 'orden' => $u->orden, 'activa' => $u->activa, 'notas' => $u->notas, 'articulos' => $u->stocks_count, 'unidades' => (float) $u->unidades]),
            'sinUbicar' => $sinUbicar, 'tipos' => Ubicacion::TIPOS,
            'movimientos' => UbicacionMovimiento::with('product:id,name', 'desde:id,codigo', 'hacia:id,codigo', 'user:id,name')->latest('id')->limit(30)->get()->map(fn($m) => ['id' => $m->id, 'fecha' => $m->created_at->format('d/m H:i'), 'articulo' => $m->product?->name, 'desde' => $m->desde?->codigo ?? 'sin ubicar', 'hacia' => $m->hacia?->codigo ?? ($m->desde ? 'salida' : '—'), 'cantidad' => (float) $m->cantidad, 'motivo' => $m->motivo, 'usuario' => $m->user?->name]),
            'comprobantes' => Comprobante::ventas()->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'REM', 'PRE'])->where('estado', '!=', 'anulado')->with('contact:id,name')->orderByDesc('fecha')->orderByDesc('id')->limit(25)->get()->map(fn($c) => ['id' => $c->id, 'nombre' => $c->nombreTipo() . ' ' . $c->numeroFormateado(), 'cliente' => $c->contact?->name, 'fecha' => $c->fecha?->format('d/m')]),
        ]);
    }

    public function guardarUbicacion(Request $request, ?int $id = null)
    {
        $d = $request->validate(['deposito_id' => 'required|exists:depositos,id', 'codigo' => 'required|string|max:40', 'pasillo' => 'nullable|string|max:20', 'estante' => 'nullable|string|max:20', 'nivel' => 'nullable|string|max:20',
            'tipo' => ['required', Rule::in(array_keys(Ubicacion::TIPOS))], 'orden' => 'nullable|integer|min:0', 'activa' => 'boolean', 'notas' => 'nullable|string|max:200']);
        $d['codigo'] = Ubicacion::normalizar($d['codigo']);
        abort_if(Ubicacion::where('codigo', $d['codigo'])->when($id, fn($q) => $q->where('id', '!=', $id))->exists(), 422, "Ya existe la ubicación {$d['codigo']}.");
        $u = $id ? Ubicacion::findOrFail($id) : new Ubicacion(['business_id' => $request->user()->business_id]);
        $u->fill($d + ['orden' => $d['orden'] ?? ((int) Ubicacion::where('deposito_id', $d['deposito_id'])->max('orden') + 1)])->save();
        return back()->with('success', "Ubicación {$u->codigo} guardada.");
    }

    public function borrarUbicacion(int $id)
    {
        $u = Ubicacion::findOrFail($id);
        abort_if(UbicacionStock::where('ubicacion_id', $u->id)->where('cantidad', '>', 0)->exists(), 422, "La ubicación {$u->codigo} tiene mercadería: movela antes de borrarla.");
        UbicacionStock::where('ubicacion_id', $u->id)->delete(); $u->delete();
        return back()->with('success', 'Ubicación borrada.');
    }

    public function generar(Request $request)
    {
        $d = $request->validate(['deposito_id' => 'required|exists:depositos,id', 'pasillos' => 'required|string|max:100', 'estantes' => 'required|integer|min:1|max:200', 'niveles' => 'required|integer|min:0|max:20', 'tipo' => ['required', Rule::in(array_keys(Ubicacion::TIPOS))], 'prefijo' => 'nullable|string|max:10']);
        // "A-D" o "1-5" arman un rango; si no, lista separada por comas.
        $p = trim($d['pasillos']);
        $pasillos = preg_match('/^([A-Za-z]|\d+)\s*-\s*([A-Za-z]|\d+)$/', $p, $m) ? (is_numeric($m[1]) ? array_map(fn($x) => str_pad((string) $x, 2, '0', STR_PAD_LEFT), range((int) $m[1], (int) $m[2])) : range(strtoupper($m[1]), strtoupper($m[2]))) : array_values(array_filter(array_map('trim', explode(',', $p))));
        abort_if(count($pasillos) * $d['estantes'] * max(1, $d['niveles']) > 3000, 422, 'Son demasiadas ubicaciones de una vez (máximo 3000).');
        $n = $this->svc->generar(Deposito::findOrFail($d['deposito_id']), $pasillos, (int) $d['estantes'], (int) $d['niveles'], $d['tipo'], (string) ($d['prefijo'] ?? ''));
        AuditLog::registrar('crear', null, "Generó {$n} ubicaciones");
        return back()->with('success', "Se crearon {$n} ubicaciones.");
    }

    public function escanear(Request $request)
    {
        $d = $request->validate(['codigo' => 'required|string|max:60']);
        $r = $this->svc->buscar($d['codigo']);
        return $r ? response()->json($r) : response()->json(['error' => "No hay ninguna ubicación ni artículo con el código {$d['codigo']}."], 404);
    }

    private function articulo(array $d): Product
    {
        if (! empty($d['product_id'])) return Product::findOrFail($d['product_id']);
        $c = trim((string) ($d['articulo'] ?? ''));
        return Product::where('barcode', $c)->orWhere('sku', $c)->firstOrFail();
    }

    private function ubicacion(string $codigo): Ubicacion
    {
        $u = Ubicacion::with('deposito')->where('codigo', Ubicacion::normalizar($codigo))->first();
        abort_unless($u, 422, "No existe la ubicación {$codigo}.");
        return $u;
    }

    public function guardar(Request $request)
    {
        $d = $request->validate(['ubicacion' => 'required|string', 'product_id' => 'nullable|integer', 'articulo' => 'nullable|string', 'cantidad' => 'required|numeric|gt:0', 'lote_id' => 'nullable|integer']);
        $u = $this->ubicacion($d['ubicacion']); $p = $this->articulo($d);
        $this->svc->guardar($u, $p, (float) $d['cantidad'], $d['lote_id'] ?? null);
        return response()->json(['ok' => true, 'mensaje' => "Guardado en {$u->codigo}: {$d['cantidad']} {$p->unit} de {$p->name}.", 'ubicacion' => $this->svc->ficha($u->fresh('deposito'))]);
    }

    public function mover(Request $request)
    {
        $d = $request->validate(['desde' => 'required|string', 'hacia' => 'required|string', 'product_id' => 'nullable|integer', 'articulo' => 'nullable|string', 'cantidad' => 'required|numeric|gt:0', 'lote_id' => 'nullable|integer']);
        $desde = $this->ubicacion($d['desde']); $hacia = $this->ubicacion($d['hacia']); $p = $this->articulo($d);
        $this->svc->mover($desde, $hacia, $p, (float) $d['cantidad'], $d['lote_id'] ?? null);
        return response()->json(['ok' => true, 'mensaje' => "Movido de {$desde->codigo} a {$hacia->codigo}: {$d['cantidad']} {$p->unit} de {$p->name}.", 'ubicacion' => $this->svc->ficha($hacia->fresh('deposito'))]);
    }

    public function contar(Request $request)
    {
        $d = $request->validate(['ubicacion' => 'required|string', 'conteos' => 'required|array|min:1', 'conteos.*' => 'numeric|min:0']);
        $u = $this->ubicacion($d['ubicacion']);
        $r = $this->svc->contar($u, $d['conteos']);
        return response()->json(['ok' => true, 'mensaje' => "Conteo de {$u->codigo} guardado (" . count($r) . ' artículos).', 'ubicacion' => $this->svc->ficha($u->fresh('deposito'))]);
    }

    public function preparacion(Request $request, int $comprobante)
    {
        $r = $this->svc->preparacion(Comprobante::ventas()->findOrFail($comprobante));
        return $request->wantsJson() ? response()->json($r) : view('stock.preparacion', ['r' => $r, 'b' => $request->user()->business]);
    }

    public function etiquetas(Request $request)
    {
        $dep = $this->deposito($request);
        $q = Ubicacion::where('deposito_id', $dep?->id)->orderBy('orden')->orderBy('codigo');
        if ($request->ids) $q->whereIn('id', array_map('intval', explode(',', (string) $request->ids)));
        return Inertia::render('Stock/AlmacenEtiquetas', ['ubicaciones' => $q->get(['id', 'codigo', 'pasillo', 'estante', 'nivel', 'tipo']), 'deposito' => $dep?->nombre, 'empresa' => $request->user()->business->name]);
    }
}
