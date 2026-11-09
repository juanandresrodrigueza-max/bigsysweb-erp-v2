<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'archivo', 'bytes', 'origen', 'resumen', 'user_id', 'verificado_en', 'verificacion', 'ensayo_en', 'ensayo_ok', 'remoto', 'remoto_en'];
    protected $casts = ['resumen' => 'array', 'verificacion' => 'array', 'verificado_en' => 'datetime', 'ensayo_en' => 'datetime', 'ensayo_ok' => 'boolean', 'remoto_en' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
