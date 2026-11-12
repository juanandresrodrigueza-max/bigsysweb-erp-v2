<?php

namespace App\Services\Suscripciones;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CuentaFondos;
use App\Models\Plan;
use App\Models\PuntoVenta;
use App\Models\Role;
use App\Models\SistemaConfig;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Fase 87: la empresa propia de BigSys dentro del ERP. Los superadmins la tienen como "su" empresa (facturan, cobran y llevan la
// gestión ahí) y saltan entre el panel de control y la empresa con un botón, sin el modo soporte. Igual que en el CRM, donde el
// administrador usa la empresa de administración como una más.
class EmpresaBigSysService
{
    public const CLAVE = 'bigsys_business_id';

    public function __construct(private SuscripcionService $suscripciones) {}

    public function actual(): ?Business
    {
        $id = (int) SistemaConfig::get(self::CLAVE);
        return $id ? Business::find($id) : null;
    }

    // Crea la empresa con el superadmin como dueño (sin usuario nuevo), todo activado y una suscripción de cortesía larga.
    public function crear(User $super, string $nombre = 'BigSysWeb'): Business
    {
        return DB::transaction(function () use ($super, $nombre) {
            $slug = \Illuminate\Support\Str::slug($nombre); $base = $slug; $i = 2;
            while (Business::withTrashed()->where('slug', $slug)->exists()) { $slug = "{$base}-{$i}"; $i++; }
            $empresa = Business::create(['name' => $nombre, 'slug' => $slug, 'email' => $super->email, 'razon_social' => $nombre, 'condicion_iva' => 'Responsable Inscripto',
                'vertical' => 'servicios', 'afip_punto_venta' => '0001', 'owner_id' => $super->id, 'alta_por' => $super->id, 'notas_internas' => 'Empresa propia de BigSys (gestión interna).',
                'modulos_contratados' => array_keys(config('erp.modulos'))]);
            $central = BusinessLocation::create(['business_id' => $empresa->id, 'name' => 'Casa Central', 'short_name' => 'CC', 'is_default' => true]);
            Role::crearRolesSistema($empresa->id);
            PuntoVenta::create(['business_id' => $empresa->id, 'business_location_id' => $central->id, 'numero' => 1, 'modo' => 'electronico']);
            CuentaFondos::create(['business_id' => $empresa->id, 'business_location_id' => $central->id, 'tipo' => 'caja', 'nombre' => 'Caja', 'es_default' => true]);
            \App\Models\Deposito::create(['business_id' => $empresa->id, 'business_location_id' => $central->id, 'nombre' => 'Depósito principal', 'es_default' => true]);
            \App\Services\Contabilidad\PlanCuentas::crear($empresa);
            $plan = Plan::whereJsonContains('features', '*')->orderBy('orden')->first() ?? Plan::where('is_active', true)->orderByDesc('price_monthly')->first() ?? Plan::first();
            $this->suscripciones->activar($empresa, $plan, 'yearly', 'cortesia', $super, now()->addYears(20));
            $this->vincular($empresa, $super);
            AuditLog::registrar('crear', $empresa, "{$super->name} creó la empresa propia de BigSys ({$empresa->name})");
            return $empresa;
        });
    }

    // Marca una empresa como la de BigSys y suma a todos los superadmins.
    public function vincular(Business $empresa, User $por): void
    {
        SistemaConfig::set(self::CLAVE, $empresa->id);
        foreach (User::where('is_superadmin', true)->get() as $u) $this->sumar($u, $empresa);
        AuditLog::registrar('editar', $empresa, "{$por->name} dejó a {$empresa->name} como la empresa propia de BigSys");
    }

    // Un superadmin entra a la empresa de BigSys como dueño (rol dueño, casa central). Si ya es de otra empresa, no se lo mueve.
    public function sumar(User $u, ?Business $empresa = null): void
    {
        $empresa ??= $this->actual();
        if (! $empresa || ! $u->is_superadmin || ($u->business_id && $u->business_id !== $empresa->id)) return;
        $central = BusinessLocation::where('business_id', $empresa->id)->orderBy('id')->first();
        $rol = Role::where('business_id', $empresa->id)->where('slug', 'dueno')->value('id');
        $u->forceFill(['business_id' => $empresa->id, 'role_id' => $u->role_id ?: $rol, 'current_location_id' => $u->current_location_id ?: $central?->id])->save();
        if ($central) $u->locations()->syncWithoutDetaching([$central->id => ['role_id' => $u->role_id]]);
    }
}
