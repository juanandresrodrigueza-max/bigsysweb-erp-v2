<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Persona de contacto de un cliente o proveedor: con quién se habla y a quién le llega cada documento.
class ContactoPersona extends Model
{
    use BelongsToBusiness;

    protected $table = 'contacto_personas';
    protected $fillable = ['business_id', 'contact_id', 'nombre', 'cargo', 'telefono', 'email', 'notas', 'recibe_comprobantes', 'recibe_cobranzas', 'recibe_pagos'];
    protected $casts = ['recibe_comprobantes' => 'boolean', 'recibe_cobranzas' => 'boolean', 'recibe_pagos' => 'boolean'];

    // Qué documentos recibe: comprobantes (facturas, presupuestos, remitos), cobranzas (recibos y resumen de cuenta), pagos (órdenes de pago y de compra).
    public const RECIBE = ['recibe_comprobantes' => 'Comprobantes', 'recibe_cobranzas' => 'Recibos y resumen de cuenta', 'recibe_pagos' => 'Órdenes de pago y de compra'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }

    public function datos(): array
    {
        return $this->only('id', 'nombre', 'cargo', 'telefono', 'email', 'notas', 'recibe_comprobantes', 'recibe_cobranzas', 'recibe_pagos');
    }
}
