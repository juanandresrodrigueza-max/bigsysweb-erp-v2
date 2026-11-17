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
            'empresa' => $b->name, 'soloDueno' => $user->esDueno(),
            'actual' => $sub ? [
                'plan' => $sub->plan->name, 'plan_id' => $sub->plan_id, 'estado' => $sub->status, 'estado_label' => $sub->estadoLabel(), 'ciclo' => $sub->billing_cycle,
                'monto' => (float) $sub->amount, 'vence' => $sub->fechaLimite()?->format('d/m/Y'), 'dias' => $sub->diasRestantes(), 'aviso' => $sub->aviso(),
            ] : null,
            'uso' => ['usuarios' => $b->users()->count(), 'sucursales' => $b->locations()->count()] + array_map(fn($u) => $u[0], app(\App\Services\Suscripciones\LimitesPlanService::class)->uso($b)),
            'planes' => Plan::where('is_active', true)->where('slug', '!=', 'prueba')->orderBy('orden')->orderBy('price_monthly')->get()->map(fn($p) => [
                'id' => $p->id, 'nombre' => $p->name, 'descripcion' => $p->description, 'mensual' => (float) $p->price_monthly, 'anual' => (float) $p->price_yearly,
                'usuarios' => $p->max_users, 'sucursales' => $p->max_locations, 'facturas' => (int) $p->max_facturas_mes, 'articulos' => (int) $p->max_products, 'gratis' => $p->is_free, 'usd' => $p->precio_usd !== null ? (float) ($p->precio_usd ?? 0) : null, 'usd_anual' => $p->precio_usd_anual !== null ? (float) $p->precio_usd_anual : null, 'recomendado' => (bool) $p->recomendado,
                'modulos' => collect(config('erp.modulos'))->filter(fn($m, $k) => in_array('*', (array) $p->features, true) || $m['core'] || in_array($k, (array) $p->features, true))->pluck('label')->values(),
            ]),
            'pagos' => PagoSuscripcion::where('business_id', $b->id)->with('plan:id,name')->latest('fecha')->latest('id')->limit(24)->get()->map(fn($p) => [
                'id' => $p->id, 'fecha' => $p->fecha->format('d/m/Y'), 'plan' => $p->plan->name, 'ciclo' => $p->ciclo, 'monto' => (float) $p->monto,
                'medio' => PagoSuscripcion::MEDIOS[$p->medio] ?? $p->medio, 'estado' => $p->estado, 'periodo' => $p->periodo_desde ? $p->periodo_desde->format('d/m/Y') . ' → ' . $p->periodo_hasta?->format('d/m/Y') : null,
            ]),
            'mercadopago' => $mp->configurado(),
            // Fase 117: débito automático (preapproval de Mercado Pago)
            'debito' => ['activo' => (bool) ($sub && $sub->auto_renew && $sub->mp_preapproval_id), 'id' => $sub?->mp_preapproval_id, 'pendiente' => (bool) PagoSuscripcion::where('business_id', $b->id)->where('medio', 'debito')->where('estado', 'pendiente')->exists()],
            'transferencia' => ['cbu' => SistemaConfig::get('transferencia_cbu'), 'alias' => SistemaConfig::get('transferencia_alias'), 'titular' => SistemaConfig::get('transferencia_titular')],
            'soporte' => ['whatsapp' => SistemaConfig::get('soporte_whatsapp'), 'email' => SistemaConfig::get('soporte_email')],
        ]);
    }

    // Fase 94.1: el dueño da de baja la empresa desde adentro (Configuración → Suscripción → Dar de baja la empresa), como
    // promete la política de privacidad. Pide el nombre de la empresa escrito a mano y un motivo; la baja es la misma que hace
    // el superadmin (empresa inactiva y borrada en suave, usuarios inactivos, aviso al CRM), así que se puede restaurar desde el panel.
    public function baja(Request $request, SuscripcionService $service)
    {
        $user = $request->user();
        abort_unless($user->esDueno(), 403, 'La baja de la empresa la pide sólo el dueño.');
        $b = $user->business;
        $d = $request->validate(['motivo' => 'required|string|max:200', 'confirmacion' => 'required|string|max:150']);
        if (mb_strtolower(trim($d['confirmacion'])) !== mb_strtolower(trim($b->name))) {
            return back()->withErrors(['confirmacion' => 'Escribí el nombre de la empresa tal cual figura para confirmar.']);
        }
        $service->darDeBaja($b, 'Baja pedida por el dueño: ' . $d['motivo']);
        \Illuminate\Support\Facades\Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login')->with('success', 'Listo: la empresa quedó dada de baja. Los datos se conservan 90 días por si volvés (escribinos y se restaura al instante); después se eliminan, salvo lo que la ley fiscal obliga a guardar.');
    }

    // Elige plan y ciclo, crea el cobro pendiente y manda a MercadoPago (o registra la transferencia a confirmar).
    public function pagar(Request $request, SuscripcionService $service, MercadoPagoSuscripcionService $mp)
    {
        $data = $request->validate(['plan_id' => 'required|exists:plans,id', 'ciclo' => 'required|in:monthly,yearly', 'medio' => 'required|in:mercadopago,debito,transferencia', 'referencia' => 'nullable|string|max:120']);
        $user = $request->user();
        abort_unless($user->esDueno() || $user->puede('configuracion', 'editar'), 403);
        $plan = Plan::findOrFail($data['plan_id']);

        if ($plan->is_free) {
            $service->activar($user->business, $plan, 'monthly', 'cortesia', $user);
            return redirect('/suscripcion')->with('success', "Plan {$plan->name} activado.");
        }

        $pago = $service->crearPago($user->business, $plan, $data['ciclo'], $data['medio'], $user, null, $data['referencia'] ?? null);

        // Fase 117: débito automático: se arma la suscripción en Mercado Pago y el dueño autoriza la tarjeta allá. El pago queda pendiente
        // hasta que llegue el primer cobro (minutos) y ahí se activa el plan.
        if ($data['medio'] === 'debito') {
            PagoSuscripcion::where('business_id', $user->business_id)->where('medio', 'debito')->where('estado', 'pendiente')->where('id', '!=', $pago->id)->update(['estado' => 'anulado']);
            $deb = $mp->crearDebito($user->business, $plan, $data['ciclo'], $user->business->email ?: $user->email);
            $pago->update(['preference_id' => $deb['id'], 'notas' => 'Débito automático: esperando la autorización de la tarjeta en Mercado Pago']);
            AuditLog::registrar('pago_suscripcion', $pago, 'Débito automático iniciado en Mercado Pago');
            return Inertia::location($deb['init_point']);
        }

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

    // Fase 117: vuelta de Mercado Pago después de autorizar (o no) el débito automático.
    public function retornoDebito(Request $request, SuscripcionService $service, MercadoPagoSuscripcionService $mp)
    {
        $user = $request->user(); $b = $user->business;
        $id = (string) ($request->query('preapproval_id') ?? $request->query('id') ?? '');
        $pago = PagoSuscripcion::where('business_id', $b->id)->where('medio', 'debito')->where('estado', 'pendiente')->when($id, fn($q) => $q->where('preference_id', $id))->latest('id')->first();
        if ($id === '' && ! $pago) return redirect('/suscripcion')->with('error', 'Mercado Pago no devolvió el débito. Si lo autorizaste, en minutos se refleja solo; si no, probá de nuevo.');
        $id = $id ?: $pago->preference_id;
        if ($request->boolean('simulado') && ! $mp->configurado()) {
            $service->activarDebito($b, $id, $user);
            if ($pago) $service->registrarCobroDebito($b->fresh()->subscription, 'SIMULADO-COBRO-' . $pago->id, (float) $pago->monto);
            return redirect('/suscripcion')->with('success', 'Débito automático simulado (sin credenciales de Mercado Pago): plan activado y se renueva solo.');
        }
        try {
            $info = $mp->consultarDebito($id);
            if ($info['status'] === 'authorized') {
                $service->activarDebito($b, $id, $user);
                $n = $service->reconciliarDebito($b->fresh()->subscription); // el primer cobro suele estar ya hecho
                return redirect('/suscripcion')->with('success', $n > 0 ? 'Débito automático activado y primer cobro aprobado. ¡Gracias! Tu plan ya está activo y se renueva solo.' : 'Débito automático autorizado. En cuanto Mercado Pago confirme el primer cobro (minutos) se activa el plan y de ahí en más se renueva solo.');
            }
            if (in_array($info['status'], ['cancelled', 'paused'], true)) { if ($pago) $service->rechazarPago($pago, 'rechazado', 'Mercado Pago: débito ' . $info['status']); return redirect('/suscripcion')->with('error', 'El débito no quedó autorizado. Podés intentarlo de nuevo o pagar con el link.'); }
        } catch (\Throwable $e) { report($e); }
        return redirect('/suscripcion')->with('success', 'Estamos esperando la confirmación de Mercado Pago. Se activa solo en cuanto llegue.');
    }

    public function cancelarDebito(Request $request, SuscripcionService $service)
    {
        $user = $request->user();
        abort_unless($user->esDueno() || $user->puede('configuracion', 'editar'), 403);
        $service->cancelarDebito($user->business, 'Cancelado por ' . $user->email, $user);
        return redirect('/suscripcion')->with('success', 'Débito automático cancelado. Tu plan sigue hasta el vencimiento; después renovás con el link o transferencia cuando quieras.');
    }

    // Aviso asincrónico de MercadoPago (ruta de API, sin CSRF).
    public function webhook(Request $request, SuscripcionService $service, MercadoPagoSuscripcionService $mp)
    {
        $tipo = $request->query('type') ?? $request->input('type') ?? $request->query('topic') ?? $request->input('action');
        $id = $request->query('data_id') ?? $request->input('data.id') ?? $request->query('id');
        // Fase 117: cobros y cambios de estado del débito automático.
        if ($id && $mp->configurado() && str_contains((string) $tipo, 'subscription_authorized_payment')) {
            try {
                $c = $mp->pagoAutorizado((string) $id);
                $sub = $c['preapproval_id'] ? \App\Models\Subscription::where('mp_preapproval_id', $c['preapproval_id'])->first() : null;
                if ($sub && (($c['pago_estado'] ?? '') === 'approved' || ($c['estado'] ?? '') === 'processed') && ($c['pago_id'] ?: $c['id'])) $service->registrarCobroDebito($sub, $c['pago_id'] ?: $c['id'], (float) $c['monto'], $c['fecha'] ?? null);
            } catch (\Throwable $e) { report($e); }
            return response()->json(['ok' => true]);
        }
        if ($id && $mp->configurado() && str_contains((string) $tipo, 'subscription_preapproval')) {
            try {
                $info = $mp->consultarDebito((string) $id);
                $sub = \App\Models\Subscription::where('mp_preapproval_id', (string) $id)->first();
                if (! $sub && ($info['external_reference'] ?? null) && preg_match('/^debito-(\d+)$/', $info['external_reference'], $m) && $info['status'] === 'authorized') { $emp = \App\Models\Business::withoutGlobalScopes()->find((int) $m[1]); if ($emp) $sub = $service->activarDebito($emp, (string) $id); }
                if ($sub) $service->estadoDebito($sub, (string) $info['status']);
            } catch (\Throwable $e) { report($e); }
            return response()->json(['ok' => true]);
        }
        if ($id && str_contains((string) $tipo, 'payment') && $mp->configurado()) {
            try {
                $info = $mp->consultarPago((string) $id);
                if (preg_match('/^debito-(\d+)$/', (string) $info['external_reference'], $m)) { // Fase 117: cobro del débito que llega como pago común
                    $sub = \App\Models\Subscription::where('business_id', (int) $m[1])->whereNotNull('mp_preapproval_id')->latest('id')->first();
                    if ($sub && $info['status'] === 'approved') $service->registrarCobroDebito($sub, (string) $id, (float) ($info['amount'] ?? 0));
                    return response()->json(['ok' => true]);
                }
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
