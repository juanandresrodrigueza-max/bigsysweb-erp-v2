<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\PagoSuscripcion;
use App\Models\SistemaConfig;
use App\Models\User;
use App\Services\Suscripciones\SuscripcionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Cobros globales, usuarios de todas las empresas, parámetros del sistema y auditoría general.
class SistemaController extends Controller
{
    public function cobros(Request $request)
    {
        $q = PagoSuscripcion::with('business:id,name', 'plan:id,name', 'user:id,name')
            ->when($request->estado, fn($q, $e) => $q->where('estado', $e))
            ->when($request->medio, fn($q, $m) => $q->where('medio', $m))
            ->when($request->buscar, fn($q, $b) => $q->whereHas('business', fn($w) => $w->where('name', 'like', "%$b%")))
            ->latest('fecha')->latest('id');

        $mes = now()->startOfMonth();
        return Inertia::render('Superadmin/Cobros', [
            'lista' => $q->paginate(30)->withQueryString()->through(fn($p) => ['id' => $p->id, 'business_id' => $p->business_id, 'empresa' => $p->business?->name, 'plan' => $p->plan->name, 'ciclo' => $p->ciclo, 'fecha' => $p->fecha->format('d/m/Y'), 'monto' => (float) $p->monto, 'medio' => $p->medio, 'medio_label' => PagoSuscripcion::MEDIOS[$p->medio] ?? $p->medio, 'estado' => $p->estado, 'referencia' => $p->referencia, 'periodo' => $p->periodo_desde ? $p->periodo_desde->format('d/m/Y') . ' → ' . $p->periodo_hasta?->format('d/m/Y') : null, 'por' => $p->user?->name]),
            'filtros' => $request->only('estado', 'medio', 'buscar'), 'estados' => PagoSuscripcion::ESTADOS, 'medios' => PagoSuscripcion::MEDIOS,
            'resumen' => [
                'mes' => (float) PagoSuscripcion::where('estado', 'aprobado')->where('aprobado_en', '>=', $mes)->sum('monto'),
                'pendientes' => (float) PagoSuscripcion::where('estado', 'pendiente')->sum('monto'), 'pendientes_n' => PagoSuscripcion::where('estado', 'pendiente')->count(),
                'anio' => (float) PagoSuscripcion::where('estado', 'aprobado')->whereYear('aprobado_en', now()->year)->sum('monto'),
            ],
        ]);
    }

    public function usuarios(Request $request)
    {
        $q = User::with('business:id,name', 'role:id,nombre')
            ->when($request->buscar, fn($q, $b) => $q->where(fn($w) => $w->where('name', 'like', "%$b%")->orWhere('email', 'like', "%$b%")))
            ->when($request->tipo === 'superadmin', fn($q) => $q->where('is_superadmin', true))
            ->when($request->tipo === 'inactivos', fn($q) => $q->where('status', '!=', 'active'))
            ->orderBy('name');
        return Inertia::render('Superadmin/Usuarios', [
            'lista' => $q->paginate(30)->withQueryString()->through(fn($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'empresa' => $u->business?->name, 'business_id' => $u->business_id, 'rol' => $u->is_superadmin ? 'Superadmin' : ($u->role?->nombre ?? '—'), 'status' => $u->status, 'is_superadmin' => $u->is_superadmin, 'ultimo' => $u->last_login_at?->diffForHumans() ?? 'Nunca']),
            'filtros' => $request->only('buscar', 'tipo'),
        ]);
    }

    public function usuario(Request $request, int $id)
    {
        $u = User::findOrFail($id);
        $d = $request->validate(['accion' => 'required|in:activar,desactivar,password,superadmin,quitar_superadmin', 'password' => 'required_if:accion,password|nullable|string|min:6']);
        abort_if($u->id === $request->user()->id && in_array($d['accion'], ['desactivar', 'quitar_superadmin'], true), 422, 'No podés quitarte el acceso a vos mismo.');
        match ($d['accion']) {
            'activar' => $u->forceFill(['status' => 'active'])->save(),
            'desactivar' => $u->forceFill(['status' => 'inactive'])->save(),
            'password' => $u->forceFill(['password' => $d['password']])->save(),
            'superadmin' => $u->forceFill(['is_superadmin' => true])->save(),
            'quitar_superadmin' => $u->forceFill(['is_superadmin' => false])->save(),
        };
        AuditLog::registrar('editar', $u, "Usuario {$u->email}: {$d['accion']} (superadmin)");
        return back()->with('success', 'Usuario actualizado.');
    }

    public function nuevoSuperadmin(Request $request)
    {
        $d = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|unique:users,email', 'password' => 'required|string|min:6']);
        $u = User::create($d + ['status' => 'active', 'is_superadmin' => true]);
        AuditLog::registrar('crear', $u, "Nuevo superadmin {$u->email}");
        return back()->with('success', 'Superadmin creado.');
    }

    public function configuracion()
    {
        $c = SistemaConfig::todo();
        return Inertia::render('Superadmin/Sistema', [
            'config' => ['dias_prueba' => $c['dias_prueba'], 'dias_gracia' => $c['dias_gracia'], 'aviso_dias' => implode(',', (array) $c['aviso_dias']), 'mp_public_key' => $c['mp_public_key'], 'mp_access_token_set' => (bool) $c['mp_access_token'], 'transferencia_cbu' => $c['transferencia_cbu'], 'transferencia_alias' => $c['transferencia_alias'], 'transferencia_titular' => $c['transferencia_titular'], 'mensaje_global' => $c['mensaje_global'], 'soporte_whatsapp' => $c['soporte_whatsapp'], 'soporte_email' => $c['soporte_email']],
            'ia' => (bool) config('services.anthropic.api_key'),
            'version' => ['php' => PHP_VERSION, 'laravel' => app()->version(), 'db' => config('database.default')],
        ]);
    }

    public function guardarConfiguracion(Request $request)
    {
        $d = $request->validate(['dias_prueba' => 'required|integer|min:1|max:365', 'dias_gracia' => 'required|integer|min:0|max:90', 'aviso_dias' => 'nullable|string|max:40', 'mp_public_key' => 'nullable|string|max:200', 'mp_access_token' => 'nullable|string|max:300', 'transferencia_cbu' => 'nullable|string|max:40', 'transferencia_alias' => 'nullable|string|max:60', 'transferencia_titular' => 'nullable|string|max:120', 'mensaje_global' => 'nullable|string|max:300', 'soporte_whatsapp' => 'nullable|string|max:40', 'soporte_email' => 'nullable|email']);
        foreach ($d as $k => $v) {
            if ($k === 'mp_access_token' && ! $v) continue; // vacío = no tocar el token guardado
            if ($k === 'aviso_dias') $v = collect(explode(',', (string) $v))->map(fn($x) => (int) trim($x))->filter(fn($x) => $x >= 0)->unique()->sortDesc()->values()->all();
            SistemaConfig::set($k, $v);
        }
        AuditLog::registrar('editar', null, 'Parámetros del sistema');
        return back()->with('success', 'Configuración guardada.');
    }

    public function revisarAhora(SuscripcionService $service)
    {
        $r = $service->revisar();
        return back()->with('success', "Revisión hecha: {$r['a_gracia']} a gracia, {$r['suspendidas']} suspendidas, {$r['avisadas']} avisadas.");
    }

    public function auditoria(Request $request)
    {
        $logs = AuditLog::withoutGlobalScopes()->with('user:id,name', 'business:id,name')
            ->when($request->accion, fn($q, $a) => $q->where('accion', $a))
            ->when($request->empresa, fn($q, $e) => $q->where('business_id', $e))
            ->latest('created_at')->paginate(40)->withQueryString()
            ->through(fn($l) => ['id' => $l->id, 'usuario' => $l->user?->name ?? 'Sistema', 'empresa' => $l->business?->name ?? '—', 'accion' => $l->accion, 'descripcion' => $l->descripcion, 'ip' => $l->ip, 'fecha' => $l->created_at->format('d/m/Y H:i')]);
        return Inertia::render('Superadmin/Auditoria', ['logs' => $logs, 'filtros' => $request->only('accion', 'empresa'), 'empresas' => Business::withTrashed()->orderBy('name')->get(['id', 'name'])]);
    }
}
