<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// La memoria del vínculo: lo que el vendedor anota del cliente aparece solo la próxima vez. "Listo" la saca de la vista, no la borra.
class NotaCliente extends Model
{
    use BelongsToBusiness;

    public $timestamps = false;
    protected $table = 'notas_cliente';
    protected $fillable = ['business_id', 'contact_id', 'user_id', 'texto', 'listo_at', 'listo_por', 'created_at'];
    protected $casts = ['listo_at' => 'datetime', 'created_at' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
