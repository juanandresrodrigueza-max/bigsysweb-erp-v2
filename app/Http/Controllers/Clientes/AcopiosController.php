<?php

namespace App\Http\Controllers\Clientes;

use App\Http\Controllers\Controller;
use App\Models\Acopio;
use App\Services\Comprobantes\AcopioService;
use Illuminate\Http\Request;

class AcopiosController extends Controller
{
    public function retirar(Request $request, int $id, AcopioService $service)
    {
        $acopio = Acopio::findOrFail($id);
        $data = $request->validate([
            'fecha' => 'required|date', 'retirado_por' => 'nullable|string|max:120', 'observaciones' => 'nullable|string|max:500',
            'items' => 'required|array|min:1', 'items.*.acopio_item_id' => 'required|integer', 'items.*.cantidad' => 'required|numeric|min:0',
        ]);
        $retiro = $service->retirar($acopio, $data);
        return back()->with('success', 'Retiro registrado. Remito ' . $retiro->remito?->numeroFormateado() . ' generado.');
    }
}
