<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class Importacion extends Model
{
    use BelongsToBusiness;

    protected $table = 'importaciones';
    protected $fillable = ['business_id', 'user_id', 'entidad', 'archivo', 'leidas', 'creadas', 'actualizadas', 'errores', 'detalle'];
    protected $casts = ['detalle' => 'array'];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(User::class); }
}
