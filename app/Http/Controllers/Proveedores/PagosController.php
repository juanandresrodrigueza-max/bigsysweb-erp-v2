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
            'fecha' => 'required|date', 'notas' => 'nullable|string|max:500', 'descuento' => 'nullable|numeric|min:0', 'interes' => 'nullable|numeric|min:0',
            'medios' => 'required|array|min:1', 'medios.*.medio' => 'required|in:' . implode(',', array_keys(Pago::MEDIOS)), 'medios.*.monto' => 'required|numeric|min:0',
            'medios.*.cuenta_fondos_id' => 'nullable|integer', 'medios.*.cheque_id' => 'nullable|integer', 'medios.*.referencia' => 'nullable|string|max:120', 'medios.*.datos' => 'nullable|array',
            'imputaciones' => 'nullable|array', 'imputaciones.*.comprobante_id' => 'required|integer', 'imputaciones.*.monto' => 'required|numeric|min:0',
        ]);
        $pago = $service->registrar($proveedor, $data);
        return back()->with('success', "Orden de pago {$pago->numeroFormateado()} registrada por $ " . number_format((float) $pago->total, 2, ',', '.') . '.');
    }

    public function anular(Request $request, int $id, PagoService $service)
    {
        $request->validate(['motivo' => 'required|string|max:255']);
        $service->anular(Pago::findOrFail($id), $request->motivo);
        return back()->with('success', 'Orden de pago anulada.');
    }

    public function imprimir(int $id)
    {
        $p = Pago::with(['contact', 'medios.cheque', 'imputaciones.comprobante', 'retenciones', 'business'])->findOrFail($id);
        return view('comprobantes.orden_pago', ['p' => $p, 'b' => $p->business]);
    }
}
