<?php

namespace App\Services\Suscripciones;

use App\Models\Alerta;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CuentaFondos;
use App\Models\PagoSuscripcion;
use App\Models\Plan;
use App\Models\PuntoVenta;
use App\Models\Role;
use App\Models\SistemaConfig;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Ciclo de vida comercial de una empresa: alta, prueba, pagos, vencimiento, gracia, suspensión y baja.
class SuscripcionService
{
    // Alta completa de una empresa: dueño, sucursal principal, roles, punto de venta, caja y suscripción.
    public function crearEmpresa(array $d, ?User $altaPor = null): Business
    {
        return DB::transaction(function () use ($d, $altaPor) {
            $dueno = User::create(['name' => $d['dueno_nombre'], 'email' => $d['dueno_email'], 'password' => $d['dueno_password'], 'status' => 'active', 'mobile' => $d['dueno_mobile'] ?? null]);

            $slug = Str::slug($d['name']);
            $base = $slug; $i = 2;
            while (Business::withTrashed()->where('slug', $slug)->exists()) { $slug = "{$base}-{$i}"; $i++; }

            $empresa = Business::create([
                'name' => $d['name'], 'slug' => $slug, 'email' => $d['email'] ?? $d['dueno_email'], 'phone' => $d['phone'] ?? null,
                'cuit' => $d['cuit'] ?? null, 'razon_social' => $d['razon_social'] ?? $d['name'], 'condicion_iva' => $d['condicion_iva'] ?? 'Responsable Inscripto',
                'vertical' => $d['vertical'] ?? 'otro', 'afip_punto_venta' => '0001', 'owner_id' => $dueno->id, 'alta_por' => $altaPor?->id, 'notas_internas' => $d['notas_internas'] ?? null,
            ]);

            $central = BusinessLocation::create(['business_id' => $empresa->id, 'name' => $d['sucursal'] ?? 'Casa Central', 'short_name' => 'CC', 'city' => $d['city'] ?? null, 'province' => $d['province'] ?? null, 'is_default' => true]);
            Role::crearRolesSistema($empresa->id);
            $dueno->forceFill(['business_id' => $empresa->id, 'role_id' => $empresa->roles()->where('slug', 'dueno')->value('id'), 'current_location_id' => $central->id])->save();
            $dueno->locations()->sync([$central->id => ['role_id' => $dueno->role_id]]);

            PuntoVenta::create(['business_id' => $empresa->id, 'business_location_id' => $central->id, 'numero' => 1, 'modo' => 'electronico']);
            CuentaFondos::create(['business_id' => $empresa->id, 'business_location_id' => $central->id, 'tipo' => 'caja', 'nombre' => 'Caja', 'es_default' => true]);
            \App\Models\Deposito::create(['business_id' => $empresa->id, 'business_location_id' => $central->id, 'nombre' => 'Depósito principal', 'es_default' => true]);

            $plan = Plan::findOrFail($d['plan_id']);
            $modo = $d['modo'] ?? 'trial';
            if ($modo === 'trial') {
                $this->iniciarPrueba($empresa, $plan, (int) ($d['dias_prueba'] ?? SistemaConfig::get('dias_prueba')));
            } else {
                $this->activar($empresa, $plan, $d['ciclo'] ?? 'monthly', $d['medio'] ?? 'cortesia', $altaPor, $d['ends_at'] ?? null);
            }

            AuditLog::registrar('alta_empresa', $empresa, "Alta de empresa {$empresa->name} (plan {$plan->name}, {$modo})");
            return $empresa->fresh();
        });
    }

    public function iniciarPrueba(Business $empresa, Plan $plan, int $dias): Subscription
    {
        return Subscription::create([
            'business_id' => $empresa->id, 'plan_id' => $plan->id, 'status' => 'trial', 'billing_cycle' => 'monthly', 'amount' => 0,
            'starts_at' => now(), 'trial_ends_at' => now()->addDays($dias)->endOfDay(), 'ends_at' => now()->addDays($dias)->endOfDay(),
        ]);
    }

    // Activa (o renueva) a mano: usado por el superadmin y por los pagos aprobados.
    public function activar(Business $empresa, Plan $plan, string $ciclo = 'monthly', string $medio = 'cortesia', ?User $por = null, $endsAt = null, ?PagoSuscripcion $pago = null): Subscription
    {
        $sub = $empresa->subscription;
        $desde = ($sub && $sub->status === 'active' && $sub->ends_at && $sub->ends_at->isFuture()) ? $sub->ends_at : now();
        $hasta = $endsAt ? \Carbon\Carbon::parse($endsAt)->endOfDay() : ($ciclo === 'yearly' ? $desde->copy()->addYear() : $desde->copy()->addMonth());
        $monto = $ciclo === 'yearly' ? $plan->price_yearly : $plan->price_monthly;

        if ($sub && $sub->status !== 'cancelled') {
            $sub->update(['plan_id' => $plan->id, 'status' => 'active', 'billing_cycle' => $ciclo, 'amount' => $monto, 'ends_at' => $hasta, 'grace_ends_at' => null, 'payment_method' => $medio, 'cancelled_at' => null]);
        } else {
            $sub = Subscription::create(['business_id' => $empresa->id, 'plan_id' => $plan->id, 'status' => 'active', 'billing_cycle' => $ciclo, 'amount' => $monto, 'starts_at' => now(), 'ends_at' => $hasta, 'payment_method' => $medio]);
        }

        $empresa->unsetRelation('subscription')->unsetRelation('activeSubscription');

        // Si estaba suspendida por falta de pago, vuelve a operar.
        if ($empresa->suspended_at && $empresa->suspension_motivo === 'Falta de pago') {
            $empresa->forceFill(['suspended_at' => null, 'suspension_motivo' => null])->save();
        }
        Alerta::withoutGlobalScopes()->where('business_id', $empresa->id)->where('tipo', 'suscripcion')->whereNull('resuelta_en')->update(['resuelta_en' => now()]);

        if ($pago) {
            $pago->update(['subscription_id' => $sub->id, 'periodo_desde' => $desde->toDateString(), 'periodo_hasta' => $hasta->toDateString()]);
        }
        AuditLog::registrar('suscripcion', $sub, "Suscripción {$plan->name} ({$ciclo}) hasta " . $hasta->format('d/m/Y') . " · {$medio}");
        return $sub->fresh();
    }

    // Crea el cobro pendiente que después se aprueba por MercadoPago, transferencia o a mano.
    public function crearPago(Business $empresa, Plan $plan, string $ciclo, string $medio, ?User $por = null, ?float $monto = null, ?string $referencia = null, ?string $notas = null): PagoSuscripcion
    {
        return PagoSuscripcion::create([
            'business_id' => $empresa->id, 'subscription_id' => $empresa->subscription?->id, 'plan_id' => $plan->id, 'fecha' => today(), 'ciclo' => $ciclo,
            'monto' => $monto ?? ($ciclo === 'yearly' ? $plan->price_yearly : $plan->price_monthly), 'medio' => $medio, 'estado' => 'pendiente',
            'referencia' => $referencia, 'notas' => $notas, 'user_id' => $por?->id,
        ]);
    }

    public function aprobarPago(PagoSuscripcion $pago, ?string $externalId = null, ?User $por = null): PagoSuscripcion
    {
        return DB::transaction(function () use ($pago, $externalId, $por) {
            if ($pago->estado === 'aprobado') {
                return $pago;
            }
            $pago->update(['estado' => 'aprobado', 'aprobado_en' => now(), 'external_id' => $externalId ?? $pago->external_id]);
            $this->activar($pago->business, $pago->plan, $pago->ciclo, $pago->medio, $por, null, $pago);
            return $pago->fresh();
        });
    }

    public function rechazarPago(PagoSuscripcion $pago, string $estado = 'rechazado', ?string $motivo = null): void
    {
        $pago->update(['estado' => $estado, 'notas' => trim(($pago->notas ? $pago->notas . "\n" : '') . ($motivo ?? ''))]);
    }

    public function suspender(Business $empresa, string $motivo, ?User $por = null): void
    {
        $empresa->forceFill(['suspended_at' => now(), 'suspension_motivo' => $motivo])->save();
        AuditLog::registrar('suspender_empresa', $empresa, "Empresa suspendida: {$motivo}");
    }

    public function reactivar(Business $empresa): void
    {
        $empresa->forceFill(['suspended_at' => null, 'suspension_motivo' => null, 'is_active' => true])->save();
        if ($empresa->subscription && $empresa->subscription->status === 'suspended') {
            $sub = $empresa->subscription;
            $sub->update(['status' => 'grace', 'grace_ends_at' => now()->addDays((int) SistemaConfig::get('dias_gracia'))->endOfDay()]);
        }
        AuditLog::registrar('reactivar_empresa', $empresa, 'Empresa reactivada');
    }

    // Baja: la empresa deja de operar y sus usuarios no pueden entrar. Los datos quedan (soft delete) por si vuelve.
    public function darDeBaja(Business $empresa, string $motivo): void
    {
        DB::transaction(function () use ($empresa, $motivo) {
            $empresa->forceFill(['is_active' => false, 'suspended_at' => now(), 'suspension_motivo' => $motivo])->save();
            $empresa->subscription?->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $empresa->users()->update(['status' => 'inactive']);
            AuditLog::registrar('baja_empresa', $empresa, "Baja de empresa: {$motivo}");
            $empresa->delete();
        });
    }

    public function restaurar(Business $empresa): void
    {
        DB::transaction(function () use ($empresa) {
            $empresa->restore();
            $empresa->forceFill(['is_active' => true, 'suspended_at' => null, 'suspension_motivo' => null])->save();
            $empresa->users()->where('id', $empresa->owner_id)->update(['status' => 'active']);
            AuditLog::registrar('restaurar_empresa', $empresa, 'Empresa restaurada');
        });
    }

    // Corre todos los días: pasa pruebas y suscripciones vencidas a gracia, gracia agotada a suspendida y avisa antes de cada vencimiento.
    public function revisar(): array
    {
        $gracia = (int) SistemaConfig::get('dias_gracia');
        $avisos = (array) SistemaConfig::get('aviso_dias');
        $r = ['a_gracia' => 0, 'suspendidas' => 0, 'avisadas' => 0];

        foreach (Subscription::vigentes()->with('business')->get() as $sub) {
            if (! $sub->business) continue;
            $limite = $sub->fechaLimite();
            $dias = $sub->diasRestantes();

            if (in_array($sub->status, ['trial', 'active'], true) && $limite && $limite->isPast()) {
                $sub->update(['status' => 'grace', 'grace_ends_at' => now()->addDays($gracia)->endOfDay()]);
                $r['a_gracia']++;
                $this->alertar($sub, 'critica', 'Suscripción vencida', "Tenés {$gracia} días para renovar antes de que se suspenda el acceso.");
                continue;
            }
            if ($sub->status === 'grace' && $limite && $limite->isPast()) {
                $sub->update(['status' => 'suspended']);
                $sub->business->forceFill(['suspended_at' => now(), 'suspension_motivo' => 'Falta de pago'])->save();
                $r['suspendidas']++;
                continue;
            }
            if ($dias !== null && in_array($dias, $avisos, true)) {
                $que = $sub->status === 'trial' ? 'período de prueba' : 'suscripción';
                $this->alertar($sub, $dias <= 1 ? 'critica' : 'aviso', ucfirst($que) . ($dias <= 0 ? ' vence hoy' : " vence en {$dias} días"), 'Renovala desde Configuración > Suscripción para no perder el acceso.');
                $r['avisadas']++;
            }
        }
        return $r;
    }

    private function alertar(Subscription $sub, string $severidad, string $titulo, string $detalle): void
    {
        Alerta::withoutGlobalScopes()->updateOrCreate(
            ['business_id' => $sub->business_id, 'tipo' => 'suscripcion', 'modelo' => 'Subscription', 'modelo_id' => $sub->id],
            ['modulo' => 'configuracion', 'severidad' => $severidad, 'titulo' => $titulo, 'detalle' => $detalle, 'url' => '/suscripcion', 'resuelta_en' => null, 'leida_por' => []]
        );
    }
}
