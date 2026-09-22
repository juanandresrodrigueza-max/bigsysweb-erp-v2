<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoCliente extends Model
{
    use BelongsToBusiness;

    protected $table = 'tipos_cliente';
    protected $fillable = ['business_id', 'nombre', 'lista_precios', 'dias_pago', 'descuento', 'limite_credito', 'color'];
    protected $casts = ['descuento' => 'decimal:2', 'limite_credito' => 'decimal:2'];

    public function contacts(): HasMany { return $this->hasMany(Contact::class, 'tipo_cliente_id'); }
}
