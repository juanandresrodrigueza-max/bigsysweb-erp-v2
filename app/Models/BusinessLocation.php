<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BusinessLocation extends Model
{
    protected $fillable = ['business_id', 'name', 'short_name', 'address', 'city', 'province', 'postal_code', 'phone', 'email', 'is_active', 'is_default',
        'cuit', 'razon_social', 'condicion_iva', 'iibb', 'inicio_actividades', 'afip_cert_path', 'afip_key_path', 'afip_produccion'];

    protected $casts = ['is_active' => 'boolean', 'is_default' => 'boolean', 'afip_produccion' => 'boolean', 'inicio_actividades' => 'date'];

    protected $hidden = ['afip_cert_path', 'afip_key_path'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_locations')->withPivot('role_id')->withTimestamps();
    }

    public function puntosVenta() { return $this->hasMany(PuntoVenta::class, 'business_location_id'); }

    // La sucursal factura con su propio CUIT (no con el de la empresa).
    public function tieneCuitPropio(): bool { return ! empty($this->cuit); }

    // Además del CUIT tiene certificado ARCA cargado: emite electrónico por su cuenta.
    public function arcaConfigurado(): bool { return $this->tieneCuitPropio() && $this->afip_cert_path && $this->afip_key_path; }
}
