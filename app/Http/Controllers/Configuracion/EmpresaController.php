<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmpresaController extends Controller
{
    public function index(Request $request)
    {
        $b = $request->user()->business;
        $sub = $b->activeSubscription;

        return Inertia::render('Configuracion/Empresa', [
            'empresa' => [
                'name' => $b->name, 'razon_social' => $b->razon_social, 'cuit' => $b->cuit, 'email' => $b->email,
                'phone' => $b->phone, 'condicion_iva' => $b->condicion_iva ?? 'Responsable Inscripto',
                'afip_punto_venta' => $b->afip_punto_venta, 'afip_produccion' => $b->afip_produccion, 'logo' => $b->logo,
            ],
            'plan' => $sub ? [
                'nombre' => $sub->plan->name, 'precio' => (float) $sub->plan->price_monthly, 'vence' => $sub->ends_at?->format('d/m/Y'),
                'estado' => $sub->status, 'usuarios' => [$b->users()->count(), $sub->plan->max_users], 'sucursales' => [$b->locations()->count(), $sub->plan->max_locations],
            ] : null,
            'modulos' => collect(config('erp.modulos'))->map(fn($m, $k) => ['key' => $k, 'label' => $m['label'], 'activo' => $b->tieneModulo($k), 'core' => $m['core']])->values(),
        ]);
    }

    public function guardar(Request $request)
    {
        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'razon_social'     => 'nullable|string|max:255',
            'cuit'             => 'nullable|string|max:20',
            'email'            => 'required|email|max:255',
            'phone'            => 'nullable|string|max:50',
            'condicion_iva'    => 'nullable|string|max:50',
            'afip_punto_venta' => 'nullable|string|max:10',
            'afip_produccion'  => 'boolean',
        ]);

        $b = $request->user()->business;
        $antes = $b->only(array_keys($data));
        $b->update($data);
        AuditLog::registrar('editar', $b, 'Datos de la empresa', $antes, $data);

        return back()->with('success', 'Datos de la empresa guardados.');
    }
}
