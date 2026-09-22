<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class RolesController extends Controller
{
    public function index(Request $request)
    {
        $b = $request->user()->business;
        $modulos = collect(config('erp.modulos'))->map(fn($m, $k) => ['key' => $k, 'label' => $m['label'], 'grupo' => $m['grupo'] ?? 'General'])->values();

        return Inertia::render('Configuracion/Roles', [
            'roles' => $b->roles()->withCount('users')->orderByDesc('es_sistema')->orderBy('nombre')->get()->map(fn($r) => [
                'id' => $r->id, 'slug' => $r->slug, 'nombre' => $r->nombre, 'descripcion' => $r->descripcion,
                'permisos' => $r->permisos, 'es_sistema' => $r->es_sistema, 'usuarios' => $r->users_count,
            ]),
            'modulos'  => $modulos,
            'acciones' => config('erp.acciones'),
        ]);
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $b = $request->user()->business;
        $data = $request->validate([
            'nombre'      => 'required|string|max:80',
            'descripcion' => 'nullable|string|max:255',
            'permisos'    => 'required|array',
            'permisos.*'  => 'array',
            'permisos.*.*' => 'in:ver,crear,editar,anular,exportar',
        ]);

        $permisos = collect($data['permisos'])->filter(fn($acciones) => ! empty($acciones))->all();
        $rol = $id ? $b->roles()->findOrFail($id) : new Role(['business_id' => $b->id, 'slug' => Str::slug($data['nombre'])]);

        if ($rol->exists && $rol->slug === 'dueno') {
            return back()->with('error', 'El rol Dueño no se puede modificar.');
        }

        $antes = $rol->exists ? ['permisos' => $rol->permisos] : null;
        $rol->fill(['nombre' => $data['nombre'], 'descripcion' => $data['descripcion'] ?? null, 'permisos' => $permisos])->save();
        AuditLog::registrar($id ? 'editar' : 'crear', $rol, "Rol {$rol->nombre}", $antes, ['permisos' => $permisos]);

        return back()->with('success', $id ? 'Rol actualizado.' : 'Rol creado.');
    }

    public function eliminar(Request $request, int $id)
    {
        $rol = $request->user()->business->roles()->findOrFail($id);
        if ($rol->es_sistema) {
            return back()->with('error', 'Los roles del sistema no se eliminan; podés editar sus permisos.');
        }
        if ($rol->users()->exists()) {
            return back()->with('error', 'Hay usuarios con este rol. Reasignalos antes de eliminarlo.');
        }
        $rol->delete();
        AuditLog::registrar('eliminar', $rol, "Rol {$rol->nombre}");
        return back()->with('success', 'Rol eliminado.');
    }
}
