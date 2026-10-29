<?php

namespace App\Http\Controllers\Fondos;

use App\Http\Controllers\Controller;
use App\Models\Conciliacion;
use App\Models\CuentaFondos;
use App\Services\Fondos\ConciliacionTarjetasService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 35: Fondos → Conciliar. Qué es cada fondo, subir la liquidación de la procesadora o billetera, el resultado en cuatro grupos y asentar.
class ConciliarController extends Controller
{
    public function __construct(private ConciliacionTarjetasService $svc) {}

    public function index()
    {
        return Inertia::render('Fondos/Conciliar', [
            'fondos' => $this->svc->fondos(), 'origenes' => Conciliacion::ORIGENES, 'sin_lector' => Conciliacion::SIN_LECTOR,
            'bancos' => CuentaFondos::where('activa', true)->whereIn('tipo', ['banco', 'billetera'])->orderBy('nombre')->get(['id', 'nombre']),
            'lotes' => Conciliacion::with(['cuenta:id,nombre', 'user:id,name'])->where('tipo', 'tarjeta')->latest('id')->limit(30)->get()->map(fn($c) => $this->fila($c)),
        ]);
    }

    private function fila(Conciliacion $c): array
    {
        return ['id' => $c->id, 'fecha' => $c->created_at->format('d/m/Y H:i'), 'origen' => Conciliacion::ORIGENES[$c->origen] ?? $c->origen, 'fondo' => $c->cuenta?->nombre, 'archivo' => $c->archivo, 'periodo' => ($c->desde?->format('d/m') ?? '') . ' al ' . ($c->hasta?->format('d/m/Y') ?? ''), 'usuario' => $c->user?->name, 'estado' => $c->estado, 'resumen' => $c->resumen];
    }

    public function guardarFondos(Request $request)
    {
        $d = $request->validate(['fondos' => 'required|array', 'fondos.*.id' => 'required|integer', 'fondos.*.concilia' => 'boolean', 'fondos.*.procesadoras' => 'nullable|array', 'fondos.*.procesadoras.*' => 'string', 'fondos.*.comision_pactada' => 'nullable|numeric|min:0|max:100', 'fondos.*.plazo_pactado' => 'nullable|integer|min:0|max:365', 'fondos.*.banco_id' => 'nullable|integer']);
        $this->svc->guardarFondos($d['fondos']);
        return back()->with('success', 'Guardado: el sistema ya sabe qué es cada fondo.');
    }

    public function subir(Request $request)
    {
        $d = $request->validate(['cuenta_fondos_id' => 'required|integer', 'origen' => 'nullable|string|max:20', 'archivo' => 'required|file|max:20480|mimes:csv,txt,xlsx,xls']);
        $c = $this->svc->subir(CuentaFondos::findOrFail($d['cuenta_fondos_id']), $request->file('archivo')->getRealPath(), $request->file('archivo')->getClientOriginalName(), $d['origen'] ?? null);
        return redirect("/fondos/conciliar/{$c->id}")->with('success', "Leí {$c->control['filas_leidas']} operaciones de " . (Conciliacion::ORIGENES[$c->origen] ?? $c->origen) . '.');
    }

    public function ver(int $id)
    {
        $c = Conciliacion::with(['cuenta:id,nombre,tipo', 'banco:id,nombre', 'user:id,name'])->findOrFail($id);
        return Inertia::render('Fondos/ConciliarVer', ['lote' => $this->fila($c) + ['resultado' => $c->resultado, 'control' => $c->control, 'banco_id' => $c->banco_id, 'banco' => $c->banco?->nombre, 'probado' => (bool) $c->probado_en, 'asentado' => $c->asentado, 'asentado_en' => $c->asentado_en?->format('d/m/Y H:i')], 'previa' => $this->svc->previa($c), 'bancos' => CuentaFondos::where('activa', true)->whereIn('tipo', ['banco', 'billetera'])->orderBy('nombre')->get(['id', 'nombre'])]);
    }

    public function resolver(Request $request, int $id)
    {
        $d = $request->validate(['fila' => 'required|integer', 'cupon_id' => 'nullable|integer']);
        $this->svc->resolver(Conciliacion::findOrFail($id), (int) $d['fila'], $d['cupon_id'] ?? null);
        return back()->with('success', 'Resuelto.');
    }

    public function banco(Request $request, int $id)
    {
        $d = $request->validate(['banco_id' => 'required|integer']);
        $c = Conciliacion::findOrFail($id); abort_if($c->estado !== 'propuesta', 422);
        $c->update(['banco_id' => $d['banco_id'], 'probado_en' => null]);
        return back()->with('success', 'Banco elegido.');
    }

    public function probar(int $id)
    {
        $r = $this->svc->probar(Conciliacion::findOrFail($id));
        return back()->with($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Prueba correcta: no se escribió nada y todo cierra. Ya podés asentar.' : 'No se puede asentar: ' . implode('; ', array_merge($r['faltan'], collect($r['acreditaciones'])->pluck('problemas')->flatten()->all())));
    }

    public function asentar(int $id)
    {
        $c = $this->svc->asentar(Conciliacion::findOrFail($id));
        return back()->with('success', 'Asentado: ' . count($c->asentado['liquidaciones']) . ' liquidaciones registradas, los cupones pasaron a liquidados y el neto entró al banco.');
    }

    public function deshacer(int $id)
    {
        $this->svc->deshacer(Conciliacion::findOrFail($id));
        return back()->with('success', 'Deshecho: las liquidaciones se anularon y los cupones volvieron a cartera.');
    }

    // Lista para tildar, imprimible / Excel: en el orden del fondo, fecha y cupón.
    public function exportar(int $id)
    {
        $c = Conciliacion::findOrFail($id);
        $csv = "Fila;Fecha venta;Importe;Cupón archivo;Cupón sistema;Cliente;Recibo;Fecha pago;Neto;Comisión;IVA;Retenciones;Acreditación\n";
        foreach ($c->resultado['listo'] as $f) $csv .= implode(';', [$f['fila'], $f['fecha_venta'], number_format($f['importe'], 2, ',', ''), $f['cupon'], $f['cupon_numero'], str_replace(';', ',', (string) $f['cliente']), $f['recibo'], $f['fecha_pago'], $f['neto'] === null ? '' : number_format($f['neto'], 2, ',', ''), number_format($f['comision'], 2, ',', ''), number_format($f['iva'], 2, ',', ''), number_format($f['retenciones'], 2, ',', ''), $f['acreditacion']]) . "\n";
        return response("\xEF\xBB\xBF" . $csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=conciliacion_{$c->id}.csv"]);
    }
}
