<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UsuariosController extends Controller
{
    public function index(Request $request)
    {
        $b = $request->user()->business;
        $max = $b->activeSubscription?->plan?->max_users ?? 1;
        $uso = app(\App\Services\Producto\UsoService::class)->porUsuario($b->id, 30);

        return Inertia::render('Configuracion/Usuarios', [
            'usuarios' => $b->users()->with(['role:id,nombre', 'locations:id,name'])->orderBy('name')->get()->map(fn($u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'status' => $u->status, 'avatar' => $u->avatar,
                'role_id' => $u->role_id, 'rol' => $u->id === $b->owner_id ? 'Dueño' : $u->role?->nombre,
                'es_dueno' => $u->id === $b->owner_id,
                'sucursales' => $u->locations->map(fn($l) => ['id' => $l->id, 'name' => $l->name, 'role_id' => $l->pivot->role_id]),
                'ultimo_acceso' => $u->last_login_at?->diffForHumans(),
                'actividad' => $uso[$u->id] ?? null,
            ]),
            'externos' => $b->usuariosExternos()->get()->map(fn($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'ultimo_acceso' => $u->last_login_at?->diffForHumans()]),
            'roles'      => $b->roles()->orderBy('nombre')->get(['id', 'nombre', 'slug']),
            'listaSucursales' => $b->locations()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'limite'     => ['max' => $max, 'usados' => $b->users()->count()],
        ]);
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $b = $request->user()->business;
        $data = $request->validate([
            'name'       => 'required|string|max:120',
            'email'      => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'password'   => [$id ? 'nullable' : 'required', 'string', \App\Support\Clave::regla()],
            'role_id'    => ['required', Rule::exists('roles', 'id')->where('business_id', $b->id)],
            'status'     => 'required|in:active,inactive',
            'sucursales' => 'array',
            'sucursales.*.id'      => [Rule::exists('business_locations', 'id')->where('business_id', $b->id)],
            'sucursales.*.role_id' => ['nullable', Rule::exists('roles', 'id')->where('business_id', $b->id)],
        ]);

        if (! $id) {
            $max = $b->activeSubscription?->plan?->max_users ?? 1;
            if ($max > 0 && $b->users()->count() >= $max) {
                return back()->with('error', "Tu plan permite hasta {$max} usuario(s). Cambiá de plan para agregar más.");
            }
        }

        $user = $id ? $b->users()->findOrFail($id) : new User(['business_id' => $b->id]);
        if ($user->exists && $user->id === $b->owner_id && $data['status'] === 'inactive') {
            return back()->with('error', 'El dueño de la empresa no se puede desactivar.');
        }

        $user->fill(['name' => $data['name'], 'email' => $data['email'], 'role_id' => $data['role_id'], 'status' => $data['status']]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        $sync = collect($data['sucursales'] ?? [])->mapWithKeys(fn($s) => [$s['id'] => ['role_id' => $s['role_id'] ?? null]])->all();
        $user->locations()->sync($sync);
        if (! $user->current_location_id || ! array_key_exists($user->current_location_id, $sync)) {
            $user->forceFill(['current_location_id' => array_key_first($sync) ?? $b->locations()->where('is_default', true)->value('id')])->save();
        }

        AuditLog::registrar($id ? 'editar' : 'crear', $user, "Usuario {$user->email}");
        return back()->with('success', $id ? 'Usuario actualizado.' : 'Usuario creado.');
    }
}
