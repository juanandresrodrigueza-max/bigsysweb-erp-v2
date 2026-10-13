<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class IaLlamada extends Model
{
    use BelongsToBusiness;

    public $timestamps = false;
    protected $table = 'ia_llamadas';
    protected $fillable = ['business_id', 'user_id', 'conexion_id', 'origen', 'herramienta', 'tipo', 'ok', 'error', 'ms', 'created_at'];
    protected $casts = ['ok' => 'boolean', 'created_at' => 'datetime'];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(User::class); }
}
