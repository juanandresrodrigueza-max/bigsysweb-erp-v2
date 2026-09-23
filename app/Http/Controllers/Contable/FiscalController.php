<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Retencion;
use App\Services\Fiscal\ArcaVentasService;
use App\Services\Fiscal\ExportacionesService;
use App\Services\Fiscal\LibroIvaDigitalService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

// Fiscal: retenciones con exportación SICORE/SIRCAR/percepciones, Libro IVA Digital y cruce con Mis Comprobantes de ARCA.
class FiscalController extends Controller
{
    private function periodo(Request $request): array
    {
        $desde = $request->desde ? Carbon::parse($request->desde) : now()->startOfMonth();
        $hasta = $request->hasta ? Carbon::parse($request->hasta) : now()->endOfMonth();
        return [$desde->toDateString(), $hasta->toDateString()];
    }

    public function index(Request $request, ExportacionesService $exp)
    {
        [$desde, $hasta] = $this->periodo($request);
        $rets = $exp->retenciones($desde, $hasta);
        $percep = \App\Models\ComprobanteImpuesto::whereHas('comprobante', fn($q) => $q->ventas()->emitidos()->whereBetween('fecha', [$desde, $hasta]))->where('tipo', 'like', 'iibb%')->with('comprobante.contact:id,name,cuit')->get();
        return Inertia::render('Contable/Fiscal', [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'retenciones' => $rets->map(fn($r) => ['id' => $r->id, 'fecha' => $r->fecha->format('d/m/Y'), 'tipo' => Retencion::TIPOS[$r->tipo] ?? $r->tipo, 'proveedor' => $r->contact?->name, 'cuit' => $r->contact?->cuit, 'pago' => $r->pago?->numero, 'pago_id' => $r->pago_id, 'base' => (float) $r->base, 'alicuota' => (float) $r->alicuota, 'monto' => (float) $r->monto, 'certificado' => $r->certificado]),
            'resumenRetenciones' => $rets->groupBy('tipo')->map(fn($g, $t) => ['tipo' => Retencion::TIPOS[$t] ?? $t, 'n' => $g->count(), 'monto' => round($g->sum('monto'), 2)])->values(),
            'percepciones' => $percep->map(fn($i) => ['id' => $i->id, 'fecha' => $i->comprobante->fecha->format('d/m/Y'), 'comprobante' => $i->comprobante->nombreTipo() . ' ' . $i->comprobante->numeroFormateado(), 'comprobante_id' => $i->comprobante_id, 'cliente' => $i->comprobante->contact?->name, 'jurisdiccion' => strtoupper(str_replace('iibb_', '', $i->tipo === 'iibb' ? 'ARBA' : $i->tipo)), 'base' => (float) $i->base, 'alicuota' => (float) $i->alicuota, 'monto' => (float) $i->monto * ($i->comprobante->def()['cc'] < 0 ? -1 : 1)]),
            'totalPercepciones' => round($percep->sum(fn($i) => (float) $i->monto * ($i->comprobante->def()['cc'] < 0 ? -1 : 1)), 2),
            'ultimoAnalisis' => session('arca_analisis'),
        ]);
    }

    public function exportar(Request $request, ExportacionesService $exp)
    {
        [$desde, $hasta] = $this->periodo($request);
        $tipo = $request->tipo;
        [$contenido, $nombre] = match ($tipo) {
            'sicore' => [$exp->sicore($desde, $hasta), "SICORE_{$desde}_{$hasta}.txt"],
            'sircar' => [$exp->sircar($desde, $hasta), "SIRCAR_{$desde}_{$hasta}.csv"],
            'percepciones' => [$exp->percepciones($desde, $hasta), "PERCEPCIONES_IIBB_{$desde}_{$hasta}.txt"],
            default => abort(404),
        };
        AuditLog::registrar('exportar', null, "Exportó {$tipo} {$desde} a {$hasta}");
        return response($contenido, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => "attachment; filename={$nombre}"]);
    }

    public function libroDigital(Request $request, LibroIvaDigitalService $svc)
    {
        [$desde, $hasta] = $this->periodo($request);
        $b = $request->user()->business;
        $archivos = $request->libro === 'compras' ? $svc->compras($b, $desde, $hasta) : $svc->ventas($b, $desde, $hasta);
        $zip = $svc->zip($archivos, 'libro');
        AuditLog::registrar('exportar', null, "Libro IVA Digital " . ($request->libro ?: 'ventas') . " {$desde} a {$hasta}");
        return response()->download($zip, 'LIBRO_IVA_DIGITAL_' . strtoupper($request->libro ?: 'ventas') . "_{$desde}_{$hasta}.zip")->deleteFileAfterSend(true);
    }

    public function arcaAnalizar(Request $request, ArcaVentasService $svc)
    {
        $request->validate(['archivo' => 'required|file|max:20480|mimes:pdf,xlsx,xls,csv,txt']);
        $r = $svc->analizar(file_get_contents($request->file('archivo')->getRealPath()));
        return back()->with('arca_analisis', $r)->with('success', "Archivo leído: {$r['leidas']} comprobantes, {$r['coinciden']} coinciden, " . count($r['faltan']) . ' faltan en el sistema.');
    }

    public function arcaRegistrar(Request $request, ArcaVentasService $svc)
    {
        $d = $request->validate(['filas' => 'required|array|min:1', 'filas.*.tipo' => 'required|integer', 'filas.*.pv' => 'required|integer', 'filas.*.numero' => 'required|integer', 'filas.*.fecha' => 'required|date', 'filas.*.neto' => 'required|numeric', 'filas.*.iva' => 'required|numeric', 'filas.*.total' => 'required|numeric', 'filas.*.cuit' => 'nullable|string', 'filas.*.nombre' => 'nullable|string', 'filas.*.cae' => 'nullable|string']);
        $n = $svc->registrar($d['filas']);
        AuditLog::registrar('crear', null, "Registró {$n} comprobantes desde Mis Comprobantes ARCA");
        return back()->with('success', "{$n} comprobantes registrados en el sistema.");
    }

    public function certificado(int $id)
    {
        $r = Retencion::with(['contact', 'pago', 'business'])->findOrFail($id);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('fiscal.certificado', ['r' => $r, 'empresa' => $r->business]);
        return $pdf->stream("certificado_retencion_{$r->id}.pdf");
    }
}
