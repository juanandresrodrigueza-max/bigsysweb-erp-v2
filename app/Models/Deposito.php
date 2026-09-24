<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Un depósito físico de una sucursal (galpón, local, camión). El stock se lleva por depósito.
class Deposito extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'business_location_id', 'nombre', 'direccion', 'es_default', 'activo'];
    protected $casts = ['es_default' => 'boolean', 'activo' => 'boolean'];

    public function location(): BelongsTo { return $this->belongsTo(BusinessLocation::class, 'business_location_id'); }
    public function stocks(): HasMany { return $this->hasMany(StockDeposito::class); }

    // Depósito por defecto de una sucursal. Si la sucursal no tiene ninguno, se crea "Depósito <sucursal>" al vuelo.
    public static function porDefecto(?int $locationId): ?self
    {
        $q = static::where('activo', true);
        if ($locationId) {
            $d = (clone $q)->where('business_location_id', $locationId)->orderByDesc('es_default')->first();
            if ($d) {
                return $d;
            }
            $loc = BusinessLocation::find($locationId);
            if ($loc) {
                return static::create(['business_id' => $loc->business_id, 'business_location_id' => $loc->id, 'nombre' => "Depósito {$loc->name}", 'es_default' => true, 'activo' => true]);
            }
        }
        return $q->orderByDesc('es_default')->first();
    }
}
