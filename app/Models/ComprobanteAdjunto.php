<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class ComprobanteAdjunto extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'comprobante_id', 'user_id', 'tipo', 'archivo', 'transcripcion', 'items_detectados', 'confianza', 'revisado'];
    protected $casts = ['items_detectados' => 'array', 'revisado' => 'boolean', 'confianza' => 'decimal:2'];
}
