<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TurnoCaja extends Model
{
    use BelongsToBusiness;

    protected $table = 'turnos_caja';
    protected $fillable = ['business_id', 'cuenta_fondos_id', 'user_id', 'apertura', 'cierre', 'saldo_inicial', 'saldo_esperado', 'saldo_contado', 'diferencia', 'notas', 'esperado_medios', 'rendicion'];
    protected $casts = ['apertura' => 'datetime', 'cierre' => 'datetime', 'saldo_inicial' => 'decimal:2', 'saldo_esperado' => 'decimal:2', 'saldo_contado' => 'decimal:2', 'diferencia' => 'decimal:2', 'esperado_medios' => 'array', 'rendicion' => 'array'];

    public function cuenta(): BelongsTo { return $this->belongsTo(CuentaFondos::class, 'cuenta_fondos_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function movimientos(): HasMany { return $this->hasMany(MovimientoFondos::class, 'turno_caja_id'); }
}
