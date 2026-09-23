<?php

namespace App\Http\Controllers\Proveedores;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Pago;
use App\Services\Compras\PagoService;
use Illuminate\Http\Request;

class PagosController extends Controller
{
    public function store(Request $request, int $contactId, PagoService $service)
    {
        $proveedor = Contact::suppliers()->findOrFail($contactId);
        $data = $request->validate([
            'fecha' => 'required|date', 'notas' => 'nullable|string|max:500', 'descuento' => 'nullable|numeric|min:0', 'interes' => 'nullable|numeric|min:0', 'cotizacion' => 'nullable|numeric|min:0',
            'medios' => 'required|array|min:1', 'medios.*.medio' => 'required|in:' . implode(',', array_keys(Pago::MEDIOS)), 'medios.*.monto' => 'required|numeric|min:0',
            'medios.*.cuenta_fondos_id' => 'nullable|integer', 'medios.*.moneda' => 'nullable|in:ARS,USD', 'medios.*.cotizacion' => 'nullable|numeric|min:0', 'medios.*.cheque_id' => 'nullable|integer', 'medios.*.referencia' => 'nullable|string|max:120', 'medios.*.datos' => 'nullable|array',
            'imputaciones' => 'nullable|array', 'imputaciones.*.comprobante_id' => 'required|integer', 'imputaciones.*.monto' => 'required|numeric|min:0',
        ]);
        $pago = $service->registrar($proveedor, $data);
        return back()->with('success', "Orden de pago {$pago->numeroFormateado()} registrada por $ " . number_format((float) $pago->total, 2, ',', '.') . '.');
    }

    public function aplicar(Request $request, int $id, PagoService $service)
    {
        $data = $request->validate(['cotizacion' => 'nullable|numeric|min:0', 'imputaciones' => 'required|array|min:1', 'imputaciones.*.comprobante_id' => 'required|integer', 'imputaciones.*.monto' => 'required|numeric|min:0']);
        $pago = $service->aplicarACuenta(Pago::findOrFail($id), $data);
        return back()->with('success', "Orden de pago {$pago->numeroFormateado()} aplicada. Queda a cuenta $ " . number_format((float) $pago->a_cuenta, 2, ',', '.') . '.');
    }

    public function anular(Request $request, int $id, PagoService $service)
    {
        $request->validate(['motivo' => 'required|string|max:255']);
        $service->anular(Pago::findOrFail($id), $request->motivo);
        return back()->with('success', 'Orden de pago anulada.');
    }

    // Retención sugerida según configuración, padrón y acumulado del mes (Ganancias RG 830).
    public function retencionSugerida(Request $request, int $id, \App\Services\Fiscal\ImpuestosService $imp)
    {
        $d = $request->validate(['tipo' => 'required|in:iibb,ganancias,iva', 'base' => 'required|numeric|min:0']);
        $prov = \App\Models\Contact::findOrFail($id);
        $acum = (float) Pago::where('contact_id', $prov->id)->where('estado', '!=', 'anulado')->whereBetween('fecha', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('total');
        return response()->json($imp->retencionSugerida($request->user()->business, $prov, $d['tipo'], (float) $d['base'], $acum) ?? ['alicuota' => 0, 'monto' => 0, 'motivo' => 'Sin regla']);
    }

    public function imprimir(int $id)
    {
        $p = Pago::with(['contact', 'medios.cheque', 'imputaciones.comprobante', 'retenciones', 'business'])->findOrFail($id);
        return view('comprobantes.orden_pago', ['p' => $p, 'b' => $p->business]);
    }
}
