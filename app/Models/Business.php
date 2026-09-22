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
        'mercadopago_settings', 'tiendanube_settings',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'afip_produccion'      => 'boolean',
        'mercadopago_settings' => 'array',
        'tiendanube_settings'  => 'array',
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

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->where('status', 'active')->latestOfMany();
    }

    public function isSubscriptionActive(): bool
    {
        return $this->activeSubscription()->exists();
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
        if (in_array('*', $features, true)) {
            return array_keys($todos);
        }
        return array_values(array_unique(array_merge($core, array_intersect(array_keys($todos), $features))));
    }

    public function tieneModulo(string $modulo): bool
    {
        return in_array($modulo, $this->modulosActivos(), true);
    }
}
