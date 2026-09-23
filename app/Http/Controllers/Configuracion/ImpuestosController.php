<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\IndiceIpc;
use App\Models\PadronIibb;
use App\Services\Contabilidad\EjercicioService;
use App\Services\Fiscal\ImpuestosService;
use App\Services\Fiscal\PadronService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Configuración fiscal: percepciones/retenciones, CBU para FCE, mes de cierre, padrones de IIBB e índices IPC.
class ImpuestosController extends Controller
{
    public function index(Request $request, ImpuestosService $imp)
    {
        $b = $request->user()->business;
        return Inertia::render('Configuracion/Impuestos', [
            'config' => $imp->config($b), 'cbu_fce' => $b->cbu_fce, 'cierre_mes' => (int) ($b->cierre_ejercicio_mes ?: 12),
            'jurisdicciones' => PadronIibb::JURISDICCIONES,
            'padrones' => PadronIibb::selectRaw('jurisdiccion, COUNT(*) as n, MAX(updated_at) as actualizado')->groupBy('jurisdiccion')->get()->map(fn($r) => ['jurisdiccion' => $r->jurisdiccion, 'nombre' => PadronIibb::JURISDICCIONES[$r->jurisdiccion] ?? $r->jurisdiccion, 'n' => $r->n, 'actualizado' => $r->actualizado ? \Carbon\Carbon::parse($r->actualizado)->format('d/m/Y') : null]),
            'ipc' => IndiceIpc::orderByDesc('periodo')->limit(24)->get(['periodo', 'valor', 'fuente']),
        ]);
    }

    public function guardar(Request $request)
    {
        $d = $request->validate([
            'impuestos' => 'required|array', 'cbu_fce' => 'nullable|string|max:22', 'cierre_mes' => 'required|integer|min:1|max:12',
        ]);
        $b = $request->user()->business;
        $b->update(['impuestos' => $d['impuestos'], 'cbu_fce' => $d['cbu_fce'] ? preg_replace('/\D/', '', $d['cbu_fce']) : null, 'cierre_ejercicio_mes' => $d['cierre_mes']]);
        AuditLog::registrar('editar', $b, 'Actualizó la configuración de impuestos');
        return back()->with('success', 'Configuración fiscal guardada.');
    }

    public function importarPadron(Request $request, PadronService $svc)
    {
        $d = $request->validate(['archivo' => 'required|file|max:204800|mimes:csv,txt,xlsx,xls,zip', 'jurisdiccion' => 'required|in:' . implode(',', array_keys(PadronIibb::JURISDICCIONES))]);
        $r = $svc->importar($request->file('archivo')->getRealPath(), $d['jurisdiccion'], $request->file('archivo')->getClientOriginalName());
        if (isset($r['error'])) return back()->withErrors(['archivo' => $r['error']]);
        AuditLog::registrar('crear', null, "Importó padrón {$d['jurisdiccion']}: {$r['importadas']} CUIT");
        return back()->with('success', "Padrón {$d['jurisdiccion']} importado: {$r['importadas']} CUIT de {$r['leidas']} líneas.");
    }

    public function guardarIpc(Request $request)
    {
        $d = $request->validate(['periodo' => 'required|date_format:Y-m', 'valor' => 'required|numeric|min:0.0001']);
        IndiceIpc::updateOrCreate(['periodo' => $d['periodo']], ['valor' => $d['valor'], 'fuente' => 'manual']);
        return back()->with('success', "IPC {$d['periodo']} guardado.");
    }

    public function actualizarIpc(EjercicioService $svc)
    {
        $r = $svc->actualizarIpc();
        if (isset($r['error'])) return back()->withErrors(['ipc' => 'No se pudo consultar INDEC (' . $r['error'] . '). Cargá el índice a mano.']);
        return back()->with('success', "IPC actualizado desde INDEC: {$r['actualizados']} períodos.");
    }
}
