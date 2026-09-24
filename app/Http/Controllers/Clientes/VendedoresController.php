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
            'vendedores' => Vendedor::with('user:id,name')->orderByDesc('activo')->orderBy('nombre')->get()->map(fn($v) => ['id' => $v->id, 'nombre' => $v->nombre, 'email' => $v->email, 'telefono' => $v->telefono, 'user_id' => $v->user_id, 'usuario' => $v->user?->name, 'comision_venta' => (float) $v->comision_venta, 'comision_cobro' => (float) $v->comision_cobro, 'activo' => $v->activo, 'clientes' => $v->hasMany(\App\Models\Contact::class)->count()]),
            'liquidacion' => $comisiones->liquidar($desde, $hasta),
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'usuarios' => User::where('business_id', $request->user()->business_id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $d = $request->validate(['nombre' => 'required|string|max:80', 'email' => 'nullable|email|max:120', 'telefono' => 'nullable|string|max:40', 'user_id' => 'nullable|exists:users,id', 'comision_venta' => 'required|numeric|min:0|max:100', 'comision_cobro' => 'required|numeric|min:0|max:100', 'activo' => 'boolean']);
        $v = $id ? Vendedor::findOrFail($id) : new Vendedor(['business_id' => $request->user()->business_id]);
        $v->fill($d + ['activo' => $d['activo'] ?? true])->save();
        AuditLog::registrar($id ? 'editar' : 'crear', $v, "Vendedor {$v->nombre}");
        return back()->with('success', $id ? 'Vendedor actualizado.' : "Vendedor {$v->nombre} creado.");
    }
}
