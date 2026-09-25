<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PagoSuscripcion;
use App\Models\Plan;
use App\Models\SistemaConfig;
use App\Services\Suscripciones\MercadoPagoSuscripcionService;
use App\Services\Suscripciones\SuscripcionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Lo que ve el dueño de una empresa sobre su plan: estado, vencimiento, renovar o cambiar de plan, historial de pagos.
class SuscripcionController extends Controller
{
    public function index(Request $request, MercadoPagoSuscripcionService $mp)
    {
        $user = $request->user();
        $b = $user->business;
        $sub = $b->subscription;

        return Inertia::render('Suscripcion/Index', [
            'bloqueada' => $b->bloqueada(), 'motivo' => $b->bloqueada() ? $b->motivoBloqueo() : null,
            // Suspensión administrativa (no es por pago): renovar no la destraba, hay que hablar con soporte.
            'administrativa' => $b->bloqueada() && (! $b->is_active || ($b->suspended_at && $b->suspension_motivo !== 'Falta de pago')),
            'esDueno' => $user->esDueno() || $user->puede('configuracion', 'editar'),
            'actual' => $sub ? [
                'plan' => $sub->plan->name, 'plan_id' => $sub->plan_id, 'estado' => $sub->status, 'estado_label' => $sub->estadoLabel(), 'ciclo' => $sub->billing_cycle,
                'monto' => (float) $sub->amount, 'vence' => $sub->fechaLimite()?->format('d/m/Y'), 'dias' => $sub->diasRestantes(), 'aviso' => $sub->aviso(),
            ] : null,
            'uso' => ['usuarios' => $b->users()->count(), 'sucursales' => $b->locations()->count()] + array_map(fn($u) => $u[0], app(\App\Services\Suscripciones\LimitesPlanService::class)->uso($b)),
            'planes' => Plan::where('is_active', true)->orderBy('price_monthly')->get()->map(fn($p) => [
                'id' => $p->id, 'nombre' => $p->name, 'descripcion' => $p->description, 'mensual' => (float) $p->price_monthly, 'anual' => (float) $p->price_yearly,
                'usuarios' => $p->max_users, 'sucursales' => $p->max_locations, 'facturas' => (int) $p->max_facturas_mes, 'articulos' => (int) $p->max_products, 'gratis' => $p->is_free,
                'modulos' => collect(config('erp.modulos'))->filter(fn($m, $k) => in_array('*', (array) $p->features, true) || $m['core'] || in_array($k, (array) $p->features, true))->pluck('label')->values(),
            ]),
            'pagos' => PagoSuscripcion::where('business_id', $b->id)->with('plan:id,name')->latest('fecha')->latest('id')->limit(24)->get()->map(fn($p) => [
                'id' => $p->id, 'fecha' => $p->fecha->format('d/m/Y'), 'plan' => $p->plan->name, 'ciclo' => $p->ciclo, 'monto' => (float) $p->monto,
                'medio' => PagoSuscripcion::MEDIOS[$p->medio] ?? $p->medio, 'estado' => $p->estado, 'periodo' => $p->periodo_desde ? $p->periodo_desde->format('d/m/Y') . ' → ' . $p->periodo_hasta?->format('d/m/Y') : null,
            ]),
            'mercadopago' => $mp->configurado(),
            'transferencia' => ['cbu' => SistemaConfig::get('transferencia_cbu'), 'alias' => SistemaConfig::get('transferencia_alias'), 'titular' => SistemaConfig::get('transferencia_titular')],
            'soporte' => ['whatsapp' => SistemaConfig::get('soporte_whatsapp'), 'email' => SistemaConfig::get('soporte_email')],
        ]);
    }

    // Elige plan y ciclo, crea el cobro pendiente y manda a MercadoPago (o registra la transferencia a confirmar).
    public function pagar(Request $request, SuscripcionService $service, MercadoPagoSuscripcionService $mp)
    {
        $data = $request->validate(['plan_id' => 'required|exists:plans,id', 'ciclo' => 'required|in:monthly,yearly', 'medio' => 'required|in:mercadopago,transferencia', 'referencia' => 'nullable|string|max:120']);
        $user = $request->user();
        abort_unless($user->esDueno() || $user->puede('configuracion', 'editar'), 403);
        $plan = Plan::findOrFail($data['plan_id']);

        if ($plan->is_free) {
            $service->activar($user->business, $plan, 'monthly', 'cortesia', $user);
            return redirect('/suscripcion')->with('success', "Plan {$plan->name} activado.");
        }

        $pago = $service->crearPago($user->business, $plan, $data['ciclo'], $data['medio'], $user, null, $data['referencia'] ?? null);

        if ($data['medio'] === 'transferencia') {
            AuditLog::registrar('pago_suscripcion', $pago, 'Transferencia informada, pendiente de confirmación');
            return redirect('/suscripcion')->with('success', 'Recibimos el aviso de transferencia. En cuanto la confirmemos se activa el plan.');
        }

        return Inertia::location($mp->linkDePago($pago));
    }

    // Vuelta de MercadoPago (o del modo simulado sin credenciales).
    public function retorno(Request $request, SuscripcionService $service, MercadoPagoSuscripcionService $mp)
    {
        $pago = PagoSuscripcion::where('business_id', $request->user()->business_id)->findOrFail($request->integer('pago'));

        if ($request->boolean('simulado') && ! $mp->configurado()) {
            $service->aprobarPago($pago, 'SIMULADO-' . $pago->id, $request->user());
            return redirect('/suscripcion')->with('success', 'Pago simulado aprobado (sin credenciales de MercadoPago). Plan activado.');
        }

        $paymentId = $request->input('payment_id') ?? $request->input('collection_id');
        if ($paymentId && $mp->configurado()) {
            try {
                $info = $mp->consultarPago($paymentId);
                if ($info['status'] === 'approved') {
                    $service->aprobarPago($pago, (string) $paymentId, $request->user());
                    return redirect('/suscripcion')->with('success', 'Pago aprobado. ¡Gracias! Tu plan ya está activo.');
                }
                if (in_array($info['status'], ['rejected', 'cancelled'], true)) {
                    $service->rechazarPago($pago, 'rechazado', 'MercadoPago: ' . $info['status']);
                    return redirect('/suscripcion')->with('error', 'El pago fue rechazado. Probá con otro medio.');
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
        return redirect('/suscripcion')->with('success', 'Estamos esperando la confirmación de MercadoPago. Se activa solo en cuanto llegue.');
    }

    // Aviso asincrónico de MercadoPago (ruta de API, sin CSRF).
    public function webhook(Request $request, SuscripcionService $service, MercadoPagoSuscripcionService $mp)
    {
        $tipo = $request->query('type') ?? $request->input('type') ?? $request->input('action');
        $id = $request->query('data_id') ?? $request->input('data.id') ?? $request->query('id');
        if ($id && str_contains((string) $tipo, 'payment') && $mp->configurado()) {
            try {
                $info = $mp->consultarPago((string) $id);
                $pago = PagoSuscripcion::find((int) $info['external_reference']);
                if ($pago && $info['status'] === 'approved') {
                    $service->aprobarPago($pago, (string) $id);
                } elseif ($pago && in_array($info['status'], ['rejected', 'cancelled'], true) && $pago->estado === 'pendiente') {
                    $service->rechazarPago($pago, 'rechazado', 'MercadoPago: ' . $info['status']);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
        return response()->json(['ok' => true]);
    }
}
