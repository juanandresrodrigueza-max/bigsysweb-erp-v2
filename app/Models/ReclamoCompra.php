<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Lo que faltó o sobró en una recepción, escrito para el proveedor. Se cierra solo con la nota de crédito o a mano.
class ReclamoCompra extends Model
{
    use BelongsToBusiness;

    protected $table = 'reclamos_compra';
    protected $fillable = ['business_id', 'contact_id', 'comprobante_id', 'recepcion_id', 'estado', 'detalle', 'texto', 'resolucion', 'nota_credito_id', 'resuelto_por', 'resuelto_en'];
    protected $casts = ['detalle' => 'array', 'resuelto_en' => 'datetime'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function comprobante(): BelongsTo { return $this->belongsTo(Comprobante::class); }
    public function scopeAbiertos($q) { return $q->where('estado', 'abierto'); }

    public function resolver(string $como, ?int $userId = null, ?int $ncId = null): void
    {
        $this->forceFill(['estado' => 'resuelto', 'resolucion' => $como, 'resuelto_por' => $userId, 'resuelto_en' => now(), 'nota_credito_id' => $ncId])->save();
    }
}
