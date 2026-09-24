<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrdenEntregaItem extends Model
{
    public $timestamps = false;
    protected $fillable = ['orden_entrega_id', 'comprobante_id', 'direccion', 'estado', 'observacion', 'orden', 'entregado_en'];
    protected $casts = ['entregado_en' => 'datetime'];

    public function comprobante(): BelongsTo { return $this->belongsTo(Comprobante::class); }
    public function hoja(): BelongsTo { return $this->belongsTo(OrdenEntrega::class, 'orden_entrega_id'); }
}
