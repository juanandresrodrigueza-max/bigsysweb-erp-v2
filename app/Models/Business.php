<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Business extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'email', 'phone', 'logo', 'currency', 'country',
        'timezone', 'locale', 'date_format', 'time_format', 'financial_year_start_month',
        'cuit', 'razon_social', 'condicion_iva', 'afip_punto_venta',
        'afip_cert_path', 'afip_key_path', 'afip_produccion', 'is_active', 'owner_id',
        'mercadopago_settings', 'tiendanube_settings', 'recordatorios', 'whatsapp_settings', 'cbu_fce', 'impuestos', 'cierre_ejercicio_mes', 'vertical', 'suspended_at', 'suspension_motivo', 'notas_internas', 'alta_por', 'onboarding_completado_en', 'onboarding', 'backup_auto', 'tienda', 'fidelizacion', 'pos', 'avisos', 'verticales_extra', 'sueldos', 'rentabilidad', 'tarjetas',
    ];

    public const VERTICALES = ['corralon' => 'Corralón / materiales', 'gastronomia' => 'Gastronomía', 'retail' => 'Comercio / indumentaria', 'minimarket' => 'Minimarket / almacén', 'servicios' => 'Servicios', 'industria' => 'Industria / producción', 'hoteleria' => 'Hotelería / alojamiento', 'otro' => 'Otro'];
    public const VERTICALES_MODULOS = ['gastronomia', 'retail', 'minimarket', 'hoteleria', 'servicios'];

    protected $casts = [
        'verticales_extra' => 'array', 'sueldos' => 'array', 'rentabilidad' => 'array', 'tarjetas' => 'array',
        'is_active'            => 'boolean',
        'recordatorios'        => 'array',
        'impuestos'            => 'array',
        'onboarding'           => 'array',
        'tienda'               => 'array',
        'pos'                  => 'array',
        'avisos'               => 'array',
        'fidelizacion'         => 'array',
        'onboarding_completado_en' => 'datetime',
        'backup_auto'          => 'boolean',
        'whatsapp_settings'    => 'encrypted:array',
        'suspended_at'         => 'datetime',
        'afip_produccion'      => 'boolean',
        'mercadopago_settings' => 'encrypted:array',
        'tiendanube_settings'  => 'encrypted:array',
    ];

    protected $hidden = ['afip_cert_path', 'afip_key_path', 'mercadopago_settings', 'tiendanube_settings'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(BusinessLocation::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    // Suscripción vigente: en prueba, activa o en gracia (todavía puede operar).
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->whereIn('status', Subscription::VIGENTES)->latestOfMany();
    }

    public function pagosSuscripcion(): HasMany
    {
        return $this->hasMany(PagoSuscripcion::class);
    }

    public function isSubscriptionActive(): bool
    {
        return $this->activeSubscription()->exists();
    }

    // La empresa no puede operar: dada de baja, suspendida a mano o sin suscripción vigente.
    public function bloqueada(): bool
    {
        return ! $this->is_active || $this->suspended_at !== null || ! $this->isSubscriptionActive();
    }

    public function motivoBloqueo(): string
    {
        if (! $this->is_active) {
            return 'La empresa fue dada de baja.';
        }
        if ($this->suspended_at) {
            return $this->suspension_motivo ?: 'La empresa está suspendida.';
        }
        $ultima = $this->subscription;
        return match ($ultima?->status) {
            'suspended' => 'La suscripción está suspendida por falta de pago.',
            'cancelled' => 'La suscripción fue cancelada.',
            default => 'La empresa no tiene una suscripción vigente.',
        };
    }

    // Módulos habilitados por el plan vigente; sin plan, solo el core.
    public function modulosActivos(): array
    {
        $todos = config('erp.modulos');
        $core  = array_keys(array_filter($todos, fn($m) => $m['core']));
        $plan  = $this->activeSubscription?->plan;
        if (! $plan) {
            return $core;
        }
        $features = (array) ($plan->features ?? []);
        $modulos = in_array('*', $features, true) ? array_keys($todos) : array_values(array_unique(array_merge($core, array_intersect(array_keys($todos), $features))));
        // Los verticales se muestran según el rubro de la empresa: un corralón no necesita ver "Gastronomía".
        $permitidos = $this->verticalesPermitidos();
        return array_values(array_filter($modulos, fn($m) => ! in_array($m, self::VERTICALES_MODULOS, true) || in_array($m, $permitidos, true)));
    }

    // Verticales visibles: los del rubro principal más los que el dueño habilitó a mano (una ferretería con cabañas, un taller con local).
    public function verticalesPermitidos(): array
    {
        $base = match ($this->vertical) {
            'gastronomia' => ['gastronomia', 'retail'],
            'minimarket' => ['minimarket'],
            'hoteleria' => ['hoteleria', 'retail'],
            'servicios' => ['servicios', 'retail'],
            default => ['retail'],
        };
        return array_values(array_unique(array_merge($base, (array) ($this->verticales_extra ?? []))));
    }

    public function tieneModulo(string $modulo): bool
    {
        return in_array($modulo, $this->modulosActivos(), true);
    }
}
