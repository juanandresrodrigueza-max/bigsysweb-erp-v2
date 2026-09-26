<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Un despiece hecho: cuántos kg entraron, cuánto salió de cada corte, la merma y el costo real por kg de cada corte.
class DespieceOperacion extends Model
{
    use BelongsToBusiness;

    protected $table = 'despiece_operaciones';
    protected $fillable = ['business_id', 'despiece_id', 'deposito_id', 'user_id', 'fecha', 'kg_entrada', 'costo_total', 'kg_salida', 'merma_kg', 'notas'];
    protected $casts = ['fecha' => 'date', 'kg_entrada' => 'decimal:3', 'costo_total' => 'decimal:2', 'kg_salida' => 'decimal:3', 'merma_kg' => 'decimal:3'];

    public function despiece(): BelongsTo { return $this->belongsTo(Despiece::class); }
    public function items(): HasMany { return $this->hasMany(DespieceOperacionItem::class, 'operacion_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
