<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Webhook saliente: la empresa recibe un POST firmado cada vez que pasa algo de la lista de eventos.
class Webhook extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'nombre', 'url', 'eventos', 'secreto', 'activo', 'fallos', 'ultimo_envio_en'];
    protected $casts = ['eventos' => 'array', 'activo' => 'boolean', 'ultimo_envio_en' => 'datetime'];

    public const EVENTOS = [
        'comprobante.emitido' => 'Comprobante emitido (factura, remito, NC…)',
        'comprobante.anulado' => 'Comprobante anulado',
        'cobro.registrado' => 'Cobro registrado',
        'pago.registrado' => 'Pago a proveedor registrado',
        'compra.registrada' => 'Compra registrada',
        'stock.bajo_minimo' => 'Artículo bajo mínimo',
        'cliente.creado' => 'Cliente creado',
        'articulo.actualizado' => 'Artículo creado o modificado',
    ];

    public function entregas(): HasMany { return $this->hasMany(WebhookEntrega::class); }
}
