<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AuditoriaController extends Controller
{
    public function index(Request $request)
    {
        $logs = AuditLog::with('user:id,name')
            ->when($request->usuario, fn($q, $u) => $q->where('user_id', $u))
            ->when($request->accion, fn($q, $a) => $q->where('accion', $a))
            ->latest('created_at')
            ->paginate(40)->withQueryString()
            ->through(fn($l) => [
                'id' => $l->id, 'usuario' => $l->user?->name ?? 'Sistema', 'accion' => $l->accion, 'modelo' => $l->modelo,
                'descripcion' => $l->descripcion, 'ip' => $l->ip, 'fecha' => $l->created_at->format('d/m/Y H:i'),
                'antes' => $l->antes, 'despues' => $l->despues,
            ]);

        return Inertia::render('Configuracion/Auditoria', [
            'logs'     => $logs,
            'usuarios' => $request->user()->business->users()->orderBy('name')->get(['id', 'name']),
            'filtros'  => $request->only('usuario', 'accion'),
        ]);
    }
}
