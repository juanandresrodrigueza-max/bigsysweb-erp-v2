<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoFondos extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    protected $table = 'movimientos_fondos';
    protected $fillable = ['business_id', 'cuenta_fondos_id', 'user_id', 'turno_caja_id', 'fecha', 'origen', 'origen_id', 'expense_category_id', 'concepto', 'ingreso', 'egreso', 'referencia', 'conciliado', 'cotizacion', 'prevision_id'];
    protected $casts = ['fecha' => 'date', 'ingreso' => 'decimal:2', 'egreso' => 'decimal:2', 'conciliado' => 'boolean'];

    public function cuenta(): BelongsTo { return $this->belongsTo(CuentaFondos::class, 'cuenta_fondos_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function categoria(): BelongsTo { return $this->belongsTo(ExpenseCategory::class, 'expense_category_id'); }
}
