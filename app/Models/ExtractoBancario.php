<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Un extracto importado del banco (CSV / Excel exportado del home banking).
class ExtractoBancario extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    protected $table = 'extractos_bancarios';
    protected $fillable = ['business_id', 'cuenta_fondos_id', 'user_id', 'archivo', 'desde', 'hasta', 'items', 'saldo_final'];
    protected $casts = ['desde' => 'date', 'hasta' => 'date', 'saldo_final' => 'decimal:2'];

    public function cuenta(): BelongsTo { return $this->belongsTo(CuentaFondos::class, 'cuenta_fondos_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function items(): HasMany { return $this->hasMany(ExtractoItem::class, 'extracto_id'); }
}
