<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\Deposito;
use App\Models\TransferenciaStock;
use App\Services\Stock\PasesService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 37.4: Stock → Pases entre sucursales.
class PasesController extends Controller
{
    public function __construct(private PasesService $svc) {}

    public function index()
    {
        return Inertia::render('Stock/Pases', [
            'pases' => TransferenciaStock::with('items.product', 'origen', 'destino', 'user')->latest('id')->limit(40)->get()->map(fn($t) => $this->svc->resumen($t)),
            'depositos' => Deposito::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function leer(Request $request)
    {
        $d = $request->validate(['codigo' => 'required|string|max:80']);
        return response()->json($this->svc->leer($d['codigo']));
    }

    public function enviar(Request $request)
    {
        $d = $request->validate(['origen_id' => 'required|integer', 'destino_id' => 'required|integer', 'notas' => 'nullable|string|max:300', 'items' => 'required|array|min:1', 'items.*.product_id' => 'required|integer', 'items.*.cantidad' => 'required|numeric|gt:0']);
        $t = $this->svc->enviar(Deposito::findOrFail($d['origen_id']), Deposito::findOrFail($d['destino_id']), $d['items'], $d['notas'] ?? null);
        return redirect("/stock/pases/{$t->id}")->with('success', "Pase {$t->numeroFormateado()} enviado: el stock ya salió de {$t->origen->nombre}.");
    }

    public function ver(int $id)
    {
        $t = TransferenciaStock::findOrFail($id);
        return Inertia::render('Stock/PaseVer', ['pase' => $this->svc->resumen($t), 'pdf' => url("/pase/{$t->token}")]);
    }

    public function recibir(Request $request, int $id)
    {
        $d = $request->validate(['conteos' => 'required|array', 'conteos.*' => 'nullable|numeric|min:0', 'notas' => 'nullable|string|max:300']);
        $t = $this->svc->recibir(TransferenciaStock::findOrFail($id), $d['conteos'], $d['notas'] ?? null);
        return back()->with($t->estado === 'recibida' ? 'success' : 'error', $t->estado === 'recibida' ? 'Recibido completo.' : 'Recibido con diferencias: quedan a la vista de las dos sucursales.');
    }

    public function devolver(int $id)
    {
        $n = $this->svc->devolverFaltante(TransferenciaStock::findOrFail($id));
        return back()->with('success', "{$n} faltantes volvieron al stock del origen.");
    }

    // El PDF de control, también por link (para mandar por WhatsApp al que recibe).
    public function pdf(string $token)
    {
        $t = TransferenciaStock::withoutGlobalScopes()->where('token', $token)->firstOrFail();
        $b = \App\Models\Business::find($t->business_id);
        $t->setRelation('items', \App\Models\TransferenciaStockItem::with(['product' => fn($q) => $q->withoutGlobalScopes()])->where('transferencia_id', $t->id)->get());
        $t->setRelation('origen', Deposito::withoutGlobalScopes()->find($t->origen_id)); $t->setRelation('destino', Deposito::withoutGlobalScopes()->find($t->destino_id));
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('stock.pase', ['t' => $t, 'b' => $b, 'estados' => PasesService::ESTADOS])->setPaper('a4')->stream("pase-{$t->numero}.pdf");
    }
}
