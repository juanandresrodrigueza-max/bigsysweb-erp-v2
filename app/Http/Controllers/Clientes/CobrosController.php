<?php

namespace App\Http\Controllers\Clientes;

use App\Http\Controllers\Controller;
use App\Models\Cobro;
use App\Models\Contact;
use App\Services\Comprobantes\CobroService;
use Illuminate\Http\Request;

class CobrosController extends Controller
{
    public function store(Request $request, int $contactId, CobroService $service)
    {
        $contact = Contact::findOrFail($contactId);
        $data = $request->validate([
            'fecha' => 'required|date', 'notas' => 'nullable|string|max:500', 'descuento' => 'nullable|numeric|min:0', 'interes' => 'nullable|numeric|min:0', 'vendedor_id' => 'nullable|exists:vendedores,id', 'cotizacion' => 'nullable|numeric|min:0',
            'medios' => 'required|array|min:1', 'medios.*.medio' => 'required|in:' . implode(',', array_keys(Cobro::MEDIOS)),
            'medios.*.monto' => 'required|numeric|min:0', 'medios.*.referencia' => 'nullable|string|max:120', 'medios.*.datos' => 'nullable|array', 'medios.*.cuenta_fondos_id' => 'nullable|integer', 'medios.*.moneda' => 'nullable|in:ARS,USD', 'medios.*.cotizacion' => 'nullable|numeric|min:0',
            'imputaciones' => 'nullable|array', 'imputaciones.*.comprobante_id' => 'required|integer', 'imputaciones.*.monto' => 'required|numeric|min:0',
        ]);
        $cobro = $service->registrar($contact, $data);
        return back()->with('success', "Cobro {$cobro->numeroFormateado()} registrado por $ " . number_format((float) $cobro->total, 2, ',', '.') . '.');
    }

    // Recibo hecho "a cuenta" (sin comprobantes): se imputa a facturas después.
    public function aplicar(Request $request, int $id, CobroService $service)
    {
        $data = $request->validate(['cotizacion' => 'nullable|numeric|min:0', 'imputaciones' => 'required|array|min:1', 'imputaciones.*.comprobante_id' => 'required|integer', 'imputaciones.*.monto' => 'required|numeric|min:0']);
        $cobro = $service->aplicarACuenta(Cobro::findOrFail($id), $data);
        return back()->with('success', "Recibo {$cobro->numeroFormateado()} aplicado. Queda a cuenta $ " . number_format((float) $cobro->a_cuenta, 2, ',', '.') . '.');
    }

    public function anular(Request $request, int $id, CobroService $service)
    {
        $request->validate(['motivo' => 'required|string|max:255']);
        $service->anular(Cobro::findOrFail($id), $request->motivo);
        return back()->with('success', 'Cobro anulado.');
    }

    public function imprimir(int $id)
    {
        $cobro = Cobro::with(['contact', 'medios', 'imputaciones.comprobante', 'business'])->findOrFail($id);
        return view('comprobantes.recibo', ['r' => $cobro, 'b' => $cobro->business]);
    }
}
