<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportacionPrecios extends Model
{
    use BelongsToBusiness;

    protected $table = 'importaciones_precios';
    protected $fillable = ['business_id', 'contact_id', 'user_id', 'archivo', 'mapeo', 'leidos', 'creados', 'actualizados', 'errores', 'detalle'];
    protected $casts = ['mapeo' => 'array', 'detalle' => 'array'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
