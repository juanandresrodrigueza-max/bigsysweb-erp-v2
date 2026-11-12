<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MejoraInteres extends Model
{
    protected $table = 'mejora_intereses';
    protected $guarded = [];
    protected $casts = ['ultimo_envio' => 'datetime', 'publicada_en' => 'datetime', 'atendido_en' => 'datetime'];
    public function business(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Business::class); } // Fase 87: Sistema la necesita para listar los intereses
}
