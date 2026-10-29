<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrdenEntregaItem extends Model
{
    public $timestamps = false;
    protected $fillable = ['orden_entrega_id', 'comprobante_id', 'direccion', 'estado', 'observacion', 'orden', 'entregado_en', 'tipo', 'contact_id', 'acopio_id', 'nombre', 'cantidades', 'token', 'lat', 'lng', 'recibio', 'foto_path', 'motivo'];
    protected $casts = ['entregado_en' => 'datetime', 'cantidades' => 'array', 'lat' => 'float', 'lng' => 'float'];
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function acopio(): BelongsTo { return $this->belongsTo(Acopio::class); }

    public function comprobante(): BelongsTo { return $this->belongsTo(Comprobante::class); }
    public function hoja(): BelongsTo { return $this->belongsTo(OrdenEntrega::class, 'orden_entrega_id'); }
}
