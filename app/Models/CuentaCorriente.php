<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CuentaCorriente extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    protected $table = 'cuenta_corriente';
    protected $fillable = ['business_id', 'contact_id', 'comprobante_id', 'cobro_id', 'pago_id', 'fecha', 'fecha_vto', 'tipo', 'concepto', 'debe', 'haber'];
    protected $casts = ['fecha' => 'date', 'fecha_vto' => 'date', 'debe' => 'decimal:2', 'haber' => 'decimal:2'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function comprobante(): BelongsTo { return $this->belongsTo(Comprobante::class); }
    public function cobro(): BelongsTo { return $this->belongsTo(Cobro::class); }
    public function pago(): BelongsTo { return $this->belongsTo(Pago::class); }

    public static function recalcularSaldo(int $contactId): float
    {
        $saldo = (float) static::where('contact_id', $contactId)->selectRaw('COALESCE(SUM(debe - haber), 0) as s')->value('s');
        Contact::where('id', $contactId)->update(['balance' => round($saldo, 2)]);
        return round($saldo, 2);
    }
}
