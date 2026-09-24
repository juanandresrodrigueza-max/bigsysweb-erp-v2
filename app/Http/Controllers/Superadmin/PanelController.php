<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\PagoSuscripcion;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Inicio del panel superadmin: cómo está el negocio de BigSys hoy.
class PanelController extends Controller
{
    public function index()
    {
        $subs = Subscription::with('business', 'plan')->whereHas('business')->get()->groupBy('business_id')->map->sortByDesc('id')->map->first();
        $porEstado = $subs->countBy('status');
        $mrr = $subs->filter(fn($s) => $s->status === 'active')->sum(fn($s) => $s->billing_cycle === 'yearly' ? (float) $s->amount / 12 : (float) $s->amount);
        $mes = now()->startOfMonth();

        $cobradoMes = (float) PagoSuscripcion::where('estado', 'aprobado')->where('aprobado_en', '>=', $mes)->sum('monto');
        $cobradoMesAnterior = (float) PagoSuscripcion::where('estado', 'aprobado')->whereBetween('aprobado_en', [$mes->copy()->subMonth(), $mes])->sum('monto');

        $vencen = $subs->filter(fn($s) => in_array($s->status, ['trial', 'active', 'grace'], true) && $s->diasRestantes() !== null && $s->diasRestantes() <= 10)
            ->sortBy(fn($s) => $s->diasRestantes())->take(10)->values()
            ->map(fn($s) => ['id' => $s->business_id, 'empresa' => $s->business->name, 'plan' => $s->plan->name, 'estado' => $s->status, 'estado_label' => $s->estadoLabel(), 'dias' => $s->diasRestantes(), 'fecha' => $s->fechaLimite()?->format('d/m')]);

        // Altas por mes (últimos 6) para la barra.
        $altas = collect(range(5, 0))->map(function ($i) {
            $m = now()->subMonths($i);
            return ['mes' => $m->locale('es')->isoFormat('MMM'), 'n' => Business::withTrashed()->whereYear('created_at', $m->year)->whereMonth('created_at', $m->month)->count()];
        });

        return Inertia::render('Superadmin/Panel', [
            'kpis' => [
                'empresas' => Business::count(), 'activas' => $porEstado->get('active', 0), 'prueba' => $porEstado->get('trial', 0),
                'gracia' => $porEstado->get('grace', 0), 'suspendidas' => $porEstado->get('suspended', 0) + Business::whereNotNull('suspended_at')->count(),
                'mrr' => round($mrr), 'cobrado_mes' => $cobradoMes, 'cobrado_anterior' => $cobradoMesAnterior, 'usuarios' => User::whereNotNull('business_id')->count(),
                'pendientes' => PagoSuscripcion::where('estado', 'pendiente')->where('medio', 'transferencia')->count(),
            ],
            'vencen' => $vencen,
            'altas' => $altas,
            'ultimosPagos' => PagoSuscripcion::with('business:id,name', 'plan:id,name')->latest('id')->limit(8)->get()->map(fn($p) => ['id' => $p->id, 'empresa' => $p->business?->name, 'business_id' => $p->business_id, 'plan' => $p->plan->name, 'monto' => (float) $p->monto, 'medio' => PagoSuscripcion::MEDIOS[$p->medio] ?? $p->medio, 'estado' => $p->estado, 'fecha' => $p->fecha->format('d/m')]),
            'nuevas' => Business::latest('id')->limit(6)->get()->map(fn($b) => ['id' => $b->id, 'nombre' => $b->name, 'vertical' => Business::VERTICALES[$b->vertical] ?? '—', 'hace' => $b->created_at->diffForHumans(), 'estado' => $b->subscription?->status]),
            'actividad' => AuditLog::withoutGlobalScopes()->with('user:id,name')->whereIn('accion', ['alta_empresa', 'baja_empresa', 'suspender_empresa', 'reactivar_empresa', 'suscripcion', 'impersonar'])->latest('created_at')->limit(8)->get()->map(fn($l) => ['id' => $l->id, 'usuario' => $l->user?->name ?? 'Sistema', 'descripcion' => $l->descripcion, 'hace' => $l->created_at->diffForHumans()]),
        ]);
    }
}
