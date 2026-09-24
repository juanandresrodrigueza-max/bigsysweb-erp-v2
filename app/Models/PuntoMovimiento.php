<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PuntoMovimiento extends Model
{
    use BelongsToBusiness;

    protected $table = 'puntos_movimientos';
    protected $fillable = ['business_id', 'contact_id', 'puntos', 'motivo', 'origen', 'origen_id'];
    protected $casts = ['puntos' => 'decimal:2'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
}
