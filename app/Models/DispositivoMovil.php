<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Fase 93: un celular con la app nativa instalada y su token de notificaciones.
class DispositivoMovil extends Model
{
    protected $table = 'dispositivos_movil';
    protected $fillable = ['user_id', 'business_id', 'plataforma', 'token', 'hash', 'modelo', 'version_app', 'ultimo_uso_en', 'ultimo_envio_en'];
    protected $casts = ['ultimo_uso_en' => 'datetime', 'ultimo_envio_en' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
