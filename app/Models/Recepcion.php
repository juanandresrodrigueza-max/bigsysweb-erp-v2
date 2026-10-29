<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// El control de una compra con la pistola: se abre desde la factura, se puede dejar a medias y se retoma desde cualquier lado.
class Recepcion extends Model
{
    use BelongsToBusiness;

    protected $table = 'recepciones';
    protected $fillable = ['business_id', 'comprobante_id', 'user_id', 'estado', 'lecturas', 'ultimas', 'cerrada_en'];
    protected $casts = ['ultimas' => 'array', 'cerrada_en' => 'datetime'];

    public function comprobante(): BelongsTo { return $this->belongsTo(Comprobante::class); }
    public function items(): HasMany { return $this->hasMany(RecepcionItem::class)->orderBy('id'); }
    public function abierta(): bool { return $this->estado === 'abierta'; }
}
