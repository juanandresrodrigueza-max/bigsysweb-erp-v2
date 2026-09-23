<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiquidacionTarjeta extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    protected $table = 'liquidaciones_tarjeta';
    protected $fillable = ['business_id', 'business_location_id', 'user_id', 'numero', 'fecha', 'tarjeta', 'cuenta_fondos_id', 'bruto', 'comision', 'iva_comision', 'ret_iva', 'ret_iibb', 'ret_ganancias', 'otros', 'neto', 'estado', 'notas'];
    protected $casts = ['fecha' => 'date', 'bruto' => 'decimal:2', 'comision' => 'decimal:2', 'iva_comision' => 'decimal:2', 'ret_iva' => 'decimal:2', 'ret_iibb' => 'decimal:2', 'ret_ganancias' => 'decimal:2', 'otros' => 'decimal:2', 'neto' => 'decimal:2'];

    public function cuenta(): BelongsTo { return $this->belongsTo(CuentaFondos::class, 'cuenta_fondos_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function cupones(): HasMany { return $this->hasMany(CuponTarjeta::class, 'liquidacion_tarjeta_id'); }
    public function numeroFormateado(): string { return 'LT-' . str_pad((string) $this->id, 5, '0', STR_PAD_LEFT); }
}
