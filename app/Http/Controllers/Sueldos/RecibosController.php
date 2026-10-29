<?php

namespace App\Http\Controllers\Sueldos;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use App\Models\Liquidacion;
use App\Models\LiquidacionItem;
use App\Models\ReciboExterno;
use App\Services\Sueldos\RecibosFirmaService;
use App\Services\Sueldos\SueldosService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

// Fase 38: Sueldos → enviar y firmar, recibos externos y datos del período.
class RecibosController extends Controller
{
    public function __construct(private RecibosFirmaService $svc) {}

    public function firmaEmpleador(Request $request)
    {
        $d = $request->validate(['firma' => 'required|string|max:300000', 'nombre' => 'nullable|string|max:120']);
        $this->svc->firmaEmpleador($request->user()->business()->first(), $d['firma'], $d['nombre'] ?? null);
        return back()->with('success', 'Firma del empleador guardada: va en todos los recibos.');
    }

    public function enviar(Request $request, int $id)
    {
        $l = Liquidacion::findOrFail($id); abort_if($l->estado === 'borrador', 422, 'Confirmá la liquidación antes de mandar los recibos.');
        $r = $this->svc->enviarLiquidacion($l, $request->user()->business()->first(), $request->boolean('faltan'));
        return back()->with('success', count($r) . ' recibos enviados.')->with('envios', $r);
    }

    public function enviarUno(Request $request, string $tipo, int $id)
    {
        $m = $tipo === 'externo' ? ReciboExterno::findOrFail($id) : LiquidacionItem::whereHas('liquidacion')->findOrFail($id);
        $r = $this->svc->enviar($m, $request->user()->business()->first());
        return back()->with('success', "Recibo enviado a {$r['empleado']}.")->with('envios', [$r]);
    }

    public function historial(Request $request)
    {
        if ($request->boolean('csv')) return response("\xEF\xBB\xBF" . $this->svc->historialCsv($request->periodo), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename=firmas_recibos.csv']);
        return Inertia::render('Sueldos/Recibos', [
            'historial' => $this->svc->historial($request->periodo), 'periodo' => $request->periodo,
            'externos' => ReciboExterno::with('empleado:id,nombre,cuil,legajo')->latest('id')->limit(200)->get()->map(fn($r) => ['id' => $r->id, 'empleado_id' => $r->empleado_id, 'empleado' => $r->empleado?->nombre, 'periodo' => $r->periodo, 'archivo' => $r->archivo_origen, 'pagina' => $r->pagina, 'por' => $r->identificado_por, 'firmado' => $r->firmado_en?->format('d/m/Y H:i'), 'enviado' => $r->enviado_en?->format('d/m/Y'), 'url' => "/sueldos/externos/{$r->id}/pdf", 'pista' => mb_substr(preg_replace('/\s+/', ' ', (string) $r->texto), 0, 140)]),
            'empleados' => Empleado::orderBy('nombre')->get(['id', 'nombre', 'cuil', 'legajo']),
            'costo' => $this->svc->costoLaboral(),
            'firma_empleador' => ($c = $request->user()->business()->first()->sueldos ?? []) && ! empty($c['firma_empleador']) ? ['nombre' => $c['firma_empleador_nombre'] ?? null, 'en' => $c['firma_empleador_en'] ?? null, 'img' => $c['firma_empleador']] : null,
        ]);
    }

    public function importar(Request $request)
    {
        $d = $request->validate(['archivo' => 'required|file|max:30720|mimes:pdf', 'periodo' => 'nullable|date_format:Y-m']);
        $r = $this->svc->importarPdf($request->file('archivo')->getRealPath(), $request->file('archivo')->getClientOriginalName(), $d['periodo'] ?? null);
        return back()->with($r['sin_asignar'] ? 'error' : 'success', "Separé {$r['hojas']} hojas: {$r['asignadas']} asignadas" . ($r['sin_asignar'] ? ", {$r['sin_asignar']} para que me digas de quién son" : '') . ($r['hojas'] > $r['nuevas'] ? ' (' . ($r['hojas'] - $r['nuevas']) . ' ya estaban)' : '') . '.');
    }

    public function asignar(Request $request, int $id)
    {
        $d = $request->validate(['empleado_id' => 'nullable|integer', 'periodo' => 'nullable|date_format:Y-m']);
        $this->svc->asignar(ReciboExterno::findOrFail($id), $d['empleado_id'] ?? null, $d['periodo'] ?? null);
        return back()->with('success', 'Recibo asignado.');
    }

    public function pdfExterno(int $id)
    {
        $r = ReciboExterno::findOrFail($id);
        return response(Storage::get($r->path), 200, ['Content-Type' => 'application/pdf']);
    }

    public function datosPeriodo(Request $request)
    {
        $d = $request->validate(['periodo' => 'required|date_format:Y-m', 'fecha_pago' => 'nullable|date', 'deposito_fecha' => 'nullable|date', 'deposito_banco' => 'nullable|string|max:60', 'deposito_periodo' => 'nullable|date_format:Y-m']);
        $n = $this->svc->datosPeriodo($d['periodo'], $d);
        return back()->with('success', "Datos del período guardados en {$n} liquidaciones: salen en todos los recibos.");
    }

    // ---- Público: el empleado ----
    public function publico(string $token)
    {
        [$r, $b] = $this->svc->porToken($token); $this->svc->visto($r);
        return view('sueldos.firmar', ['r' => $r, 'b' => $b, 'token' => $token, 'externo' => $r instanceof ReciboExterno, 'empleado' => $r->empleado, 'periodo' => $r instanceof ReciboExterno ? $r->periodo : $r->liquidacion?->periodo]);
    }

    public function ver(string $token)
    {
        [$r, $b] = $this->svc->porToken($token);
        if ($r instanceof ReciboExterno) return response(Storage::get($r->path), 200, ['Content-Type' => 'application/pdf']);
        $liq = Liquidacion::withoutGlobalScopes()->find($r->liquidacion_id);
        return view('sueldos.recibo', ['liq' => $liq, 'items' => collect([$r]), 'b' => $b, 'cfg' => app(SueldosService::class)->config($b), 'marca' => $b->marcaImpresion()]);
    }

    public function firmar(Request $request, string $token)
    {
        [$r] = $this->svc->porToken($token);
        $d = $request->validate(['firma' => 'required|string|max:300000', 'conformidad' => 'required|in:conforme,disconforme', 'observacion' => 'nullable|string|max:500']);
        $this->svc->firmar($r, $d['firma'], $d['conformidad'], $d['observacion'] ?? null, $request->ip(), $request->userAgent());
        return redirect("/recibo/{$token}");
    }
}
