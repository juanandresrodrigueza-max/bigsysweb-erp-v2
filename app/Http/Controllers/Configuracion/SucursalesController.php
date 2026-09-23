<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BusinessLocation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SucursalesController extends Controller
{
    public function index(Request $request)
    {
        $b = $request->user()->business;
        $max = $b->activeSubscription?->plan?->max_locations ?? 1;

        return Inertia::render('Configuracion/Sucursales', [
            'items' => $b->locations()->withCount('users')->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn($l) => $l->only('id', 'name', 'short_name', 'address', 'city', 'province', 'phone', 'email', 'is_active', 'is_default') + ['usuarios' => $l->users_count]),
            'limite' => ['max' => $max, 'usadas' => $b->locations()->count()],
        ]);
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $b = $request->user()->business;
        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'short_name'  => 'nullable|string|max:20',
            'address'     => 'nullable|string|max:255',
            'city'        => 'nullable|string|max:100',
            'province'    => 'nullable|string|max:100',
            'phone'       => 'nullable|string|max:50',
            'email'       => 'nullable|email|max:255',
            'is_active'   => 'boolean',
            'is_default'  => 'boolean',
        ]);

        if (! $id) {
            $max = $b->activeSubscription?->plan?->max_locations ?? 1;
            if ($max > 0 && $b->locations()->count() >= $max) {
                return back()->with('error', "Tu plan permite hasta {$max} sucursal(es). Cambiá de plan para agregar más.");
            }
        }

        $sucursal = $id ? $b->locations()->findOrFail($id) : new BusinessLocation(['business_id' => $b->id]);
        $antes = $sucursal->exists ? $sucursal->only(array_keys($data)) : null;
        $sucursal->fill($data)->save();
        if (! $id) {
            \App\Models\Deposito::porDefecto($sucursal->id); // crea el depósito principal de la sucursal nueva
        }

        if ($data['is_default'] ?? false) {
            $b->locations()->where('id', '!=', $sucursal->id)->update(['is_default' => false]);
        }

        AuditLog::registrar($id ? 'editar' : 'crear', $sucursal, "Sucursal {$sucursal->name}", $antes, $data);
        return back()->with('success', $id ? 'Sucursal actualizada.' : 'Sucursal creada.');
    }
}
