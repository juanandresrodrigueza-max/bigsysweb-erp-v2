<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Deposito;
use App\Models\Lote;
use App\Models\Product;
use App\Services\Stock\LotesService;
use App\Services\Stock\StockService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 27.1: tablero de lotes y vencimientos, bloqueo, retiro del mercado, baja de vencidos y trazabilidad.
class LotesController extends Controller
{
    public function __construct(private LotesService $svc) {}

    public function index(Request $request)
    {
        $cfg = $this->svc->config($request->user()->business);
        $dias = (int) ($request->dias ?: $cfg['dias_aviso']);
        $hoy = today()->toDateString(); $hasta = today()->addDays($dias)->toDateString();
        $f = $request->filtro ?: 'atencion';
        $q = Lote::with('product:id,name,sku,unit,price', 'deposito:id,nombre', 'proveedor:id,name')->where('cantidad', '>', 0)
            ->when($request->deposito, fn($q, $d) => $q->where('deposito_id', $d))
            ->when($request->buscar, fn($q, $b) => $q->where(fn($w) => $w->where('lote', 'like', "%{$b}%")->orWhere('serie', 'like', "%{$b}%")->orWhereHas('product', fn($p) => $p->where('name', 'like', "%{$b}%")->orWhere('sku', $b)->orWhere('barcode', $b))));
        match ($f) {
            'vencidos' => $q->whereNotNull('vencimiento')->where('vencimiento', '<', $hoy),
            'por_vencer' => $q->whereNotNull('vencimiento')->whereBetween('vencimiento', [$hoy, $hasta]),
            'bloqueados' => $q->where('estado', '!=', 'disponible'),
            'atencion' => $q->where(fn($w) => $w->where('estado', '!=', 'disponible')->orWhere(fn($v) => $v->whereNotNull('vencimiento')->where('vencimiento', '<=', $hasta))),
            default => null,
        };
        $lotes = $q->orderByRaw('CASE WHEN vencimiento IS NULL THEN 1 ELSE 0 END')->orderBy('vencimiento')->orderBy('id')->limit(500)->get();
        $base = Lote::where('cantidad', '>', 0);
        $valor = fn($qq) => round((float) (clone $qq)->selectRaw('SUM(cantidad * costo_unit) as v')->value('v'), 2);
        $venc = (clone $base)->whereNotNull('vencimiento')->where('vencimiento', '<', $hoy);
        $prox = (clone $base)->whereNotNull('vencimiento')->whereBetween('vencimiento', [$hoy, $hasta]);
        return Inertia::render('Stock/Lotes', [
            'lotes' => $lotes->map(fn($l) => ['id' => $l->id, 'producto' => $l->product?->name, 'sku' => $l->product?->sku, 'product_id' => $l->product_id, 'unidad' => $l->product?->unit, 'lote' => $l->lote, 'serie' => $l->serie,
                'vencimiento' => $l->vencimiento?->toDateString(), 'dias' => $l->vencimiento ? (int) today()->diffInDays($l->vencimiento, false) : null, 'cantidad' => (float) $l->cantidad,
                'valor' => round((float) $l->cantidad * (float) $l->costo_unit, 2), 'deposito' => $l->deposito?->nombre, 'proveedor' => $l->proveedor?->name, 'ingreso' => $l->ingreso?->format('d/m/Y'), 'estado' => $l->estado, 'motivo' => $l->motivo]),
            'kpis' => ['vencidos' => (clone $venc)->count(), 'vencidos_valor' => $valor($venc), 'por_vencer' => (clone $prox)->count(), 'por_vencer_valor' => $valor($prox), 'bloqueados' => (clone $base)->where('estado', '!=', 'disponible')->count()],
            'filtros' => ['filtro' => $f, 'dias' => $dias, 'buscar' => $request->buscar, 'deposito' => $request->deposito],
            'depositos' => Deposito::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']), 'config' => $cfg, 'estados' => Lote::ESTADOS,
        ]);
    }

    public function ver(int $id)
    {
        $l = Lote::with('product:id,name,sku,unit', 'proveedor:id,name', 'deposito:id,nombre')->findOrFail($id);
        return response()->json(['lote' => ['id' => $l->id, 'producto' => $l->product?->name, 'etiqueta' => $l->etiqueta(), 'estado' => $l->estado, 'motivo' => $l->motivo, 'cantidad' => (float) $l->cantidad, 'proveedor' => $l->proveedor?->name, 'deposito' => $l->deposito?->nombre]] + $this->svc->trazabilidad($l));
    }

    public function estado(Request $request, int $id)
    {
        $d = $request->validate(['estado' => 'required|in:disponible,bloqueado,retirado', 'motivo' => 'nullable|string|max:200', 'todos_los_depositos' => 'boolean']);
        $l = Lote::findOrFail($id);
        // Un retiro del mercado alcanza al mismo lote en todos los depósitos.
        $lotes = ($d['todos_los_depositos'] ?? $d['estado'] === 'retirado') && $l->lote ? Lote::where('product_id', $l->product_id)->where('lote', $l->lote)->get() : collect([$l]);
        foreach ($lotes as $x) $x->update(['estado' => $d['estado'], 'motivo' => $d['estado'] === 'disponible' ? null : ($d['motivo'] ?? null)]);
        AuditLog::registrar('editar', $l, ucfirst(Lote::ESTADOS[$d['estado']]) . ": {$l->product?->name} {$l->etiqueta()}" . (! empty($d['motivo']) ? " · {$d['motivo']}" : ''));
        return back()->with('success', match ($d['estado']) { 'disponible' => 'Lote habilitado para la venta.', 'bloqueado' => 'Lote bloqueado: no se vende hasta habilitarlo.', default => 'Lote retirado del mercado. En la trazabilidad tenés a qué clientes se vendió.' });
    }

    // Baja de la mercadería del lote (vencida, rota, retirada): ajuste de stock que queda en el kardex con el lote.
    public function baja(Request $request, int $id, StockService $stock)
    {
        $d = $request->validate(['cantidad' => 'nullable|numeric|gt:0', 'motivo' => 'nullable|string|max:120']);
        $l = Lote::with('product')->findOrFail($id);
        $cant = min((float) ($d['cantidad'] ?? $l->cantidad), (float) $l->cantidad);
        abort_if($cant <= 0, 422, 'El lote no tiene stock.');
        $motivo = ($d['motivo'] ?? null) ?: ($l->vencido() ? 'Vencido' : 'Baja');
        $stock->mover($l->product, -$cant, $l->deposito ?? Deposito::porDefecto(null), 'ajuste', "Baja lote {$l->etiqueta()} · {$motivo}", $l, null, null, ['lote_id' => $l->id, 'permitir_vencidos' => true, 'incluir_bloqueados' => true]);
        return back()->with('success', 'Baja registrada: ' . rtrim(rtrim(number_format($cant, 3, ',', '.'), '0'), ',') . " {$l->product->unit} de {$l->product->name}.");
    }

    public function config(Request $request)
    {
        $d = $request->validate(['bloquear_vencidos' => 'boolean', 'dias_aviso' => 'required|integer|min:1|max:365', 'exigir_serie' => 'boolean']);
        $request->user()->business->update(['lotes_config' => $d]);
        return back()->with('success', 'Configuración de lotes guardada.');
    }

    // Lotes vendibles de un artículo (para elegir en la factura).
    public function de(int $product)
    {
        $p = Product::findOrFail($product);
        return response()->json(Lote::with('deposito:id,nombre')->where('product_id', $p->id)->where('cantidad', '>', 0)->where('estado', 'disponible')->where(fn($w) => $w->whereNull('vencimiento')->orWhere('vencimiento', '>=', today()->toDateString()))
            ->orderByRaw('CASE WHEN vencimiento IS NULL THEN 1 ELSE 0 END')->orderBy('vencimiento')->get()->map(fn($l) => ['id' => $l->id, 'etiqueta' => $l->etiqueta(), 'serie' => $l->serie, 'cantidad' => (float) $l->cantidad, 'deposito' => $l->deposito?->nombre]));
    }

    // Planilla del retiro: clientes que recibieron el lote, con cuánto y cómo contactarlos.
    public function retiroCsv(int $id)
    {
        $l = Lote::with('product')->findOrFail($id);
        $t = $this->svc->trazabilidad($l);
        $csv = "Cliente;Cantidad;Comprobantes;Email;Telefono\n";
        foreach ($t['clientes'] as $c) $csv .= implode(';', [str_replace(';', ',', (string) $c['nombre']), number_format($c['cantidad'], 3, ',', ''), $c['comprobantes'], $c['email'], $c['telefono']]) . "\n";
        AuditLog::registrar('exportar', $l, "Exportó la planilla de retiro de {$l->product?->name} {$l->etiqueta()}");
        return response("\xEF\xBB\xBF" . $csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename=retiro_lote_' . preg_replace('/\W/', '', (string) ($l->lote ?: $l->id)) . '.csv']);
    }
}
