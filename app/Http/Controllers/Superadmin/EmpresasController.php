<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Comprobante;
use App\Models\PagoSuscripcion;
use App\Models\Plan;
use App\Models\SistemaConfig;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Suscripciones\SuscripcionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// Altas, bajas, suspensión, planes, pagos y acceso a cada empresa cliente de BigSys.
class EmpresasController extends Controller
{
    public function index(Request $request)
    {
        $q = Business::withTrashed()->with(['subscription.plan', 'owner:id,name,email,last_login_at'])->withCount('users')
            ->when($request->buscar, fn($q, $b) => $q->where(fn($w) => $w->where('name', 'like', "%$b%")->orWhere('cuit', 'like', "%$b%")->orWhere('email', 'like', "%$b%")))
            ->when($request->plan, fn($q, $p) => $q->whereHas('subscription', fn($s) => $s->where('plan_id', $p)))
            ->when($request->estado, function ($q, $e) {
                return match ($e) {
                    'baja' => $q->onlyTrashed(),
                    'suspendida' => $q->whereNotNull('suspended_at')->whereNull('deleted_at'),
                    default => $q->whereNull('deleted_at')->whereHas('subscription', fn($s) => $s->where('status', $e)),
                };
            }, fn($q) => $q->whereNull('deleted_at'))
            ->orderBy('name');

        $lista = $q->paginate(25)->withQueryString()->through(fn($b) => $this->fila($b));

        return Inertia::render('Superadmin/Empresas', [
            'lista' => $lista, 'filtros' => $request->only('buscar', 'estado', 'plan'),
            'planes' => Plan::orderBy('price_monthly')->get(['id', 'name', 'price_monthly', 'price_yearly', 'is_free', 'is_active']),
            'estados' => Subscription::ESTADOS, 'verticales' => Business::VERTICALES, 'diasPrueba' => (int) SistemaConfig::get('dias_prueba'),
        ]);
    }

    private function fila(Business $b): array
    {
        $s = $b->subscription;
        return [
            'id' => $b->id, 'nombre' => $b->name, 'cuit' => $b->cuit, 'email' => $b->email, 'vertical' => Business::VERTICALES[$b->vertical] ?? '—',
            'plan' => $s?->plan?->name, 'estado' => $b->trashed() ? 'baja' : ($b->suspended_at ? 'suspendida' : ($s?->status ?? 'sin_plan')),
            'estado_label' => $b->trashed() ? 'Dada de baja' : ($b->suspended_at ? 'Suspendida' : ($s?->estadoLabel() ?? 'Sin plan')),
            'vence' => $s?->fechaLimite()?->format('d/m/Y'), 'dias' => $s?->diasRestantes(), 'usuarios' => $b->users_count,
            'dueno' => $b->owner?->name, 'ultimo_acceso' => $b->owner?->last_login_at?->diffForHumans(), 'alta' => $b->created_at->format('d/m/Y'),
        ];
    }

    public function store(Request $request, SuscripcionService $service)
    {
        $d = $request->validate([
            'name' => 'required|string|max:120', 'cuit' => 'nullable|string|max:20', 'email' => 'nullable|email', 'phone' => 'nullable|string|max:40',
            'vertical' => ['nullable', Rule::in(array_keys(Business::VERTICALES))], 'condicion_iva' => 'nullable|string|max:40', 'city' => 'nullable|string|max:80', 'province' => 'nullable|string|max:80',
            'dueno_nombre' => 'required|string|max:120', 'dueno_email' => 'required|email|unique:users,email', 'dueno_password' => 'required|string|min:6', 'dueno_mobile' => 'nullable|string|max:40',
            'plan_id' => 'required|exists:plans,id', 'modo' => 'required|in:trial,activa', 'dias_prueba' => 'nullable|integer|min:1|max:365', 'ciclo' => 'nullable|in:monthly,yearly', 'medio' => 'nullable|in:cortesia,transferencia,efectivo,mercadopago', 'notas_internas' => 'nullable|string',
        ]);
        $empresa = $service->crearEmpresa($d, $request->user());
        return redirect("/admin/empresas/{$empresa->id}")->with('success', "Empresa {$empresa->name} dada de alta. El dueño ya puede entrar con {$d['dueno_email']}.");
    }

    public function show(int $id)
    {
        $b = Business::withTrashed()->with(['subscription.plan', 'owner', 'locations'])->findOrFail($id);
        $s = $b->subscription;
        $mes = now()->startOfMonth();

        return Inertia::render('Superadmin/EmpresaVer', [
            'empresa' => $this->fila($b) + [
                'razon_social' => $b->razon_social, 'phone' => $b->phone, 'condicion_iva' => $b->condicion_iva, 'vertical_key' => $b->vertical, 'notas_internas' => $b->notas_internas,
                'suspension_motivo' => $b->suspension_motivo, 'suspended_at' => $b->suspended_at?->format('d/m/Y'), 'dada_de_baja' => $b->trashed(), 'is_active' => $b->is_active,
                'dueno_email' => $b->owner?->email, 'dueno_id' => $b->owner_id, 'sucursales' => $b->locations->map(fn($l) => ['id' => $l->id, 'nombre' => $l->name, 'ciudad' => $l->city, 'activa' => $l->is_active]),
            ],
            'suscripcion' => $s ? ['id' => $s->id, 'plan_id' => $s->plan_id, 'plan' => $s->plan->name, 'estado' => $s->status, 'estado_label' => $s->estadoLabel(), 'ciclo' => $s->billing_cycle, 'monto' => (float) $s->amount, 'inicio' => $s->starts_at?->format('d/m/Y'), 'vence' => $s->ends_at?->format('d/m/Y'), 'vence_iso' => $s->ends_at?->toDateString(), 'prueba_hasta' => $s->trial_ends_at?->format('d/m/Y'), 'gracia_hasta' => $s->grace_ends_at?->format('d/m/Y'), 'dias' => $s->diasRestantes(), 'notas' => $s->notas] : null,
            'uso' => [
                'usuarios' => $b->users()->count(), 'comprobantes_mes' => Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('estado', 'emitido')->where('fecha', '>=', $mes)->count(),
                'comprobantes_total' => Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->count(), 'ultimo_login' => User::where('business_id', $b->id)->max('last_login_at'),
            ],
            'usoDetalle' => app(\App\Services\Producto\UsoService::class)->empresa($b, 30),
            'usuarios' => User::where('business_id', $b->id)->with('role:id,nombre')->orderBy('name')->get()->map(fn($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'rol' => $u->role?->nombre, 'status' => $u->status, 'es_dueno' => $u->id === $b->owner_id, 'ultimo' => $u->last_login_at?->format('d/m/Y H:i')]),
            'pagos' => PagoSuscripcion::where('business_id', $b->id)->with('plan:id,name', 'user:id,name')->latest('fecha')->latest('id')->get()->map(fn($p) => ['id' => $p->id, 'fecha' => $p->fecha->format('d/m/Y'), 'plan' => $p->plan->name, 'ciclo' => $p->ciclo, 'monto' => (float) $p->monto, 'medio' => $p->medio, 'medio_label' => PagoSuscripcion::MEDIOS[$p->medio] ?? $p->medio, 'estado' => $p->estado, 'referencia' => $p->referencia, 'periodo' => $p->periodo_desde ? $p->periodo_desde->format('d/m/Y') . ' → ' . $p->periodo_hasta?->format('d/m/Y') : null, 'por' => $p->user?->name]),
            'auditoria' => AuditLog::withoutGlobalScopes()->where('business_id', $b->id)->with('user:id,name')->latest('created_at')->limit(15)->get()->map(fn($l) => ['id' => $l->id, 'usuario' => $l->user?->name ?? 'Sistema', 'accion' => $l->accion, 'descripcion' => $l->descripcion, 'fecha' => $l->created_at->format('d/m/Y H:i')]),
            'planes' => Plan::orderBy('price_monthly')->get(['id', 'name', 'price_monthly', 'price_yearly', 'is_free']),
            'medios' => PagoSuscripcion::MEDIOS, 'verticales' => Business::VERTICALES, 'estados' => Subscription::ESTADOS,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $b = Business::withTrashed()->findOrFail($id);
        $d = $request->validate(['name' => 'required|string|max:120', 'razon_social' => 'nullable|string|max:150', 'cuit' => 'nullable|string|max:20', 'email' => 'required|email', 'phone' => 'nullable|string|max:40', 'vertical' => ['nullable', Rule::in(array_keys(Business::VERTICALES))], 'condicion_iva' => 'nullable|string|max:40', 'notas_internas' => 'nullable|string']);
        $b->update($d);
        AuditLog::registrar('editar', $b, 'Datos de la empresa (superadmin)');
        return back()->with('success', 'Empresa actualizada.');
    }

    public function suspender(Request $request, int $id, SuscripcionService $service)
    {
        $d = $request->validate(['motivo' => 'required|string|max:200']);
        $service->suspender(Business::findOrFail($id), $d['motivo'], $request->user());
        return back()->with('success', 'Empresa suspendida. Sus usuarios ven la pantalla de renovación.');
    }

    public function reactivar(int $id, SuscripcionService $service)
    {
        $service->reactivar(Business::withTrashed()->findOrFail($id));
        return back()->with('success', 'Empresa reactivada.');
    }

    public function baja(Request $request, int $id, SuscripcionService $service)
    {
        $d = $request->validate(['motivo' => 'required|string|max:200']);
        $service->darDeBaja(Business::findOrFail($id), $d['motivo']);
        return back()->with('success', 'Empresa dada de baja. Los datos quedan guardados por si vuelve.');
    }

    public function restaurar(int $id, SuscripcionService $service)
    {
        $service->restaurar(Business::withTrashed()->findOrFail($id));
        return back()->with('success', 'Empresa restaurada.');
    }

    // Cambio de plan o extensión manual (bonificación, acuerdo comercial, corrección).
    public function cambiarPlan(Request $request, int $id, SuscripcionService $service)
    {
        $d = $request->validate(['plan_id' => 'required|exists:plans,id', 'ciclo' => 'required|in:monthly,yearly', 'ends_at' => 'required|date', 'medio' => 'required|in:cortesia,transferencia,efectivo,mercadopago', 'notas' => 'nullable|string|max:300']);
        $b = Business::withTrashed()->findOrFail($id);
        $sub = $service->activar($b, Plan::findOrFail($d['plan_id']), $d['ciclo'], $d['medio'], $request->user(), $d['ends_at']);
        if ($d['notas'] ?? null) $sub->update(['notas' => $d['notas']]);
        return back()->with('success', "Plan {$sub->plan->name} vigente hasta " . $sub->ends_at->format('d/m/Y') . '.');
    }

    public function extenderPrueba(Request $request, int $id)
    {
        $d = $request->validate(['dias' => 'required|integer|min:1|max:180']);
        $b = Business::findOrFail($id);
        $s = $b->subscription;
        abort_unless($s && in_array($s->status, ['trial', 'grace', 'suspended'], true), 422, 'Solo se extiende una prueba, gracia o suspensión.');
        $hasta = now()->addDays($d['dias'])->endOfDay();
        $s->update(['status' => 'trial', 'trial_ends_at' => $hasta, 'ends_at' => $hasta, 'grace_ends_at' => null]);
        if ($b->suspension_motivo === 'Falta de pago') $b->forceFill(['suspended_at' => null, 'suspension_motivo' => null])->save();
        AuditLog::registrar('suscripcion', $s, "Prueba extendida {$d['dias']} días (hasta " . $hasta->format('d/m/Y') . ')');
        return back()->with('success', "Prueba extendida hasta " . $hasta->format('d/m/Y') . '.');
    }

    // Registrar un cobro recibido fuera de MercadoPago (transferencia, efectivo) o bonificar un período.
    public function registrarPago(Request $request, int $id, SuscripcionService $service)
    {
        $d = $request->validate(['plan_id' => 'required|exists:plans,id', 'ciclo' => 'required|in:monthly,yearly', 'medio' => 'required|in:transferencia,efectivo,cortesia,mercadopago', 'monto' => 'required|numeric|min:0', 'referencia' => 'nullable|string|max:120', 'notas' => 'nullable|string|max:300']);
        $b = Business::withTrashed()->findOrFail($id);
        $pago = $service->crearPago($b, Plan::findOrFail($d['plan_id']), $d['ciclo'], $d['medio'], $request->user(), (float) $d['monto'], $d['referencia'] ?? null, $d['notas'] ?? null);
        $service->aprobarPago($pago, null, $request->user());
        return back()->with('success', 'Pago registrado y plan renovado.');
    }

    public function aprobarPago(Request $request, int $id, int $pagoId, SuscripcionService $service)
    {
        $pago = PagoSuscripcion::where('business_id', $id)->findOrFail($pagoId);
        $service->aprobarPago($pago, null, $request->user());
        return back()->with('success', 'Pago confirmado y plan renovado.');
    }

    public function rechazarPago(Request $request, int $id, int $pagoId, SuscripcionService $service)
    {
        $pago = PagoSuscripcion::where('business_id', $id)->findOrFail($pagoId);
        $service->rechazarPago($pago, 'rechazado', 'Rechazado por ' . $request->user()->name);
        return back()->with('success', 'Pago marcado como rechazado.');
    }

    // Entrar como el dueño de la empresa para dar soporte. Queda auditado y se puede volver con un click.
    public function impersonar(Request $request, int $id)
    {
        $b = Business::findOrFail($id);
        $dueno = $b->owner ?? User::where('business_id', $b->id)->first();
        abort_if(! $dueno, 422, 'La empresa no tiene usuarios.');
        $super = $request->user();
        AuditLog::registrar('impersonar', $b, "{$super->name} entró como {$dueno->name} ({$b->name})");
        $request->session()->put('impersonando_desde', $super->id);
        Auth::login($dueno);
        $request->session()->regenerate();
        $request->session()->put('impersonando_desde', $super->id);
        return redirect('/dashboard');
    }

    public function volver(Request $request)
    {
        $superId = $request->session()->pull('impersonando_desde');
        abort_unless($superId, 403);
        $super = User::where('is_superadmin', true)->findOrFail($superId);
        Auth::login($super);
        $request->session()->regenerate();
        return redirect('/admin/empresas');
    }

    public function usuario(Request $request, int $id, int $userId)
    {
        $u = User::where('business_id', $id)->findOrFail($userId);
        $d = $request->validate(['accion' => 'required|in:activar,desactivar,password', 'password' => 'required_if:accion,password|nullable|string|min:6']);
        match ($d['accion']) {
            'activar' => $u->forceFill(['status' => 'active'])->save(),
            'desactivar' => $u->forceFill(['status' => 'inactive'])->save(),
            'password' => $u->forceFill(['password' => $d['password']])->save(),
        };
        AuditLog::registrar('editar', $u, "Usuario {$u->email}: {$d['accion']} (superadmin)");
        return back()->with('success', 'Usuario actualizado.');
    }
}
