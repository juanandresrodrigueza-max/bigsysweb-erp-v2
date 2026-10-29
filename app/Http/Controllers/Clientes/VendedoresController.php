<?php

namespace App\Http\Controllers\Clientes;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vendedor;
use App\Services\Comprobantes\ComisionesService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Vendedores y liquidación de comisiones por período.
class VendedoresController extends Controller
{
    public function index(Request $request, ComisionesService $comisiones)
    {
        $desde = $request->input('desde', today()->startOfMonth()->toDateString());
        $hasta = $request->input('hasta', today()->toDateString());
        return Inertia::render('Clientes/Vendedores', [
            'vendedores' => Vendedor::with('user:id,name')->orderByDesc('activo')->orderBy('nombre')->get()->map(fn($v) => ['id' => $v->id, 'nombre' => $v->nombre, 'email' => $v->email, 'telefono' => $v->telefono, 'user_id' => $v->user_id, 'usuario' => $v->user?->name, 'comision_venta' => (float) $v->comision_venta, 'comision_cobro' => (float) $v->comision_cobro, 'activo' => $v->activo, 'modo_default' => $v->modo_default, 'objetivo_mensual' => $v->objetivo_mensual !== null ? (float) $v->objetivo_mensual : null, 'clientes' => $v->hasMany(\App\Models\Contact::class)->count()]),
            'liquidacion' => $comisiones->liquidar($desde, $hasta),
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'usuarios' => User::where('business_id', $request->user()->business_id)->orderBy('name')->get(['id', 'name']),
            'calle' => app(\App\Services\Ventas\VenderService::class)->config($request->user()->business), 'modos' => \App\Services\Ventas\VenderService::MODOS, 'autorizaciones' => app(\App\Services\Ventas\VenderService::class)->autorizaciones(),
            'rendiciones' => app(\App\Services\Ventas\CobranzaCalleService::class)->rendiciones(), 'cajas' => \App\Models\CuentaFondos::where('activa', true)->whereNull('vendedor_id')->orderBy('tipo')->get(['id', 'tipo', 'nombre']),
        ]);
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $d = $request->validate(['nombre' => 'required|string|max:80', 'email' => 'nullable|email|max:120', 'telefono' => 'nullable|string|max:40', 'user_id' => 'nullable|exists:users,id', 'comision_venta' => 'required|numeric|min:0|max:100', 'comision_cobro' => 'required|numeric|min:0|max:100', 'activo' => 'boolean', 'modo_default' => 'nullable|in:calle,mostrador,caja', 'objetivo_mensual' => 'nullable|numeric|min:0']);
        $v = $id ? Vendedor::findOrFail($id) : new Vendedor(['business_id' => $request->user()->business_id]);
        $v->fill($d + ['activo' => $d['activo'] ?? true])->save();
        AuditLog::registrar($id ? 'editar' : 'crear', $v, "Vendedor {$v->nombre}");
        return back()->with('success', $id ? 'Vendedor actualizado.' : "Vendedor {$v->nombre} creado.");
    }

    // Fase 29.5: el encargado recibe la plata que rindió el vendedor.
    public function recibirRendicion(Request $request, int $id)
    {
        $d = $request->validate(['caja_id' => 'nullable|integer', 'banco_id' => 'nullable|integer', 'efectivo_contado' => 'nullable|numeric|min:0']);
        $r = app(\App\Services\Ventas\CobranzaCalleService::class)->recibir(\App\Models\RendicionCalle::findOrFail($id), $request->user(), $d);
        return back()->with('success', "Rendición {$r->numeroFormateado()} recibida" . (abs((float) $r->diferencia) >= 0.01 ? ' con ' . ($r->diferencia < 0 ? 'faltante' : 'sobrante') . ' de $ ' . number_format(abs($r->diferencia), 2, ',', '.') : '') . '.');
    }

    // Fase 29: cómo se vende en la calle (con qué comprobante arranca, si se emite, tope de descuento, mora tolerada).
    public function configCalle(Request $request)
    {
        $d = $request->validate(['calle_comprobante' => 'required|in:PRE,FX,REM', 'calle_emite' => 'boolean', 'tope_descuento' => 'nullable|numeric|min:0|max:100', 'dias_mora' => 'nullable|integer|min:0|max:365', 'avisar_datos_dias' => 'nullable|integer|min:0|max:90']);
        $b = $request->user()->business;
        $b->update(['ventas_config' => array_replace($b->ventas_config ?? [], $d)]);
        AuditLog::registrar('editar', $b, 'Configuración de venta en la calle');
        return back()->with('success', 'Guardado.');
    }
}
