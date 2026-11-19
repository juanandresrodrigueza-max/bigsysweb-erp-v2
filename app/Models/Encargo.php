<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Fase 120.6: algo que una persona del equipo le dejó encargado a otra («prepará el pedido 12 para las 17»).
class Encargo extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'de_user_id', 'para_user_id', 'texto', 'detalle', 'url', 'vence_en', 'estado', 'hecho_en', 'nota', 'origen', 'aviso', 'recordado_en'];
    protected $casts = ['vence_en' => 'datetime', 'hecho_en' => 'datetime', 'recordado_en' => 'datetime'];

    public function de(): BelongsTo { return $this->belongsTo(User::class, 'de_user_id'); }
    public function para(): BelongsTo { return $this->belongsTo(User::class, 'para_user_id'); }
    public function vencido(): bool { return $this->estado === 'pendiente' && $this->vence_en && $this->vence_en->isPast(); }
}
