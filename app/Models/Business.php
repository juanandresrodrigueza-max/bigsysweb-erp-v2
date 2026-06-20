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
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'afip_produccion' => 'boolean',
    ];

    protected $hidden = ['afip_cert_path', 'afip_key_path'];

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
}
