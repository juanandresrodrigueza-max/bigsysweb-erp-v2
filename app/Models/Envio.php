<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Registro de cada mail o WhatsApp que sale del sistema (o queda listo para mandar a mano).
class Envio extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'user_id', 'contact_id', 'modelo', 'modelo_id', 'canal', 'tipo', 'destino', 'asunto', 'cuerpo', 'estado', 'link', 'error', 'enviado_en'];
    protected $casts = ['enviado_en' => 'datetime'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
