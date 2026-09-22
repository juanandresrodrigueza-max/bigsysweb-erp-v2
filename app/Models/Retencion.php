<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Retencion extends Model
{
    use BelongsToBusiness;

    protected $table = 'retenciones';

    public const TIPOS = ['ganancias' => 'Ganancias', 'iva' => 'IVA', 'iibb' => 'Ingresos Brutos', 'suss' => 'SUSS'];

    protected $fillable = ['business_id', 'pago_id', 'contact_id', 'tipo', 'jurisdiccion', 'base', 'alicuota', 'monto', 'certificado', 'fecha'];
    protected $casts = ['base' => 'decimal:2', 'alicuota' => 'decimal:3', 'monto' => 'decimal:2', 'fecha' => 'date'];

    public function pago(): BelongsTo { return $this->belongsTo(Pago::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
}
