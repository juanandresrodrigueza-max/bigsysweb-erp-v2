<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Liquidación de sueldos de un período (mensual, SAC o final).
class Liquidacion extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    public const TIPOS = ['mensual' => 'Mensual', 'sac' => 'Aguinaldo (SAC)', 'final' => 'Liquidación final'];
    public const ESTADOS = ['borrador' => 'Borrador', 'confirmada' => 'Confirmada', 'pagada' => 'Pagada'];

    protected $table = 'liquidaciones';
    protected $fillable = ['business_id', 'user_id', 'periodo', 'tipo', 'estado', 'fecha', 'total_bruto', 'total_no_rem', 'total_deducciones', 'total_neto', 'total_contribuciones', 'importada', 'asiento_id', 'pagada_en', 'notas', 'deposito_fecha', 'deposito_banco', 'deposito_periodo'];
    protected $casts = ['deposito_fecha' => 'date', 'fecha' => 'date', 'pagada_en' => 'datetime', 'importada' => 'boolean', 'total_bruto' => 'decimal:2', 'total_no_rem' => 'decimal:2', 'total_deducciones' => 'decimal:2', 'total_neto' => 'decimal:2', 'total_contribuciones' => 'decimal:2'];

    public function items() { return $this->hasMany(LiquidacionItem::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function asiento() { return $this->belongsTo(Asiento::class); }

    public function periodoLabel(): string { return ucfirst(\Carbon\Carbon::parse($this->periodo . '-01')->locale('es')->isoFormat('MMMM YYYY')) . ($this->tipo !== 'mensual' ? ' · ' . self::TIPOS[$this->tipo] : ''); }

    public function recalcular(): void
    {
        $i = $this->items()->get();
        $this->update(['total_bruto' => $i->sum('bruto'), 'total_no_rem' => $i->sum('no_rem'), 'total_deducciones' => $i->sum('deducciones'), 'total_neto' => $i->sum('neto'), 'total_contribuciones' => $i->sum('contribuciones')]);
    }
}
