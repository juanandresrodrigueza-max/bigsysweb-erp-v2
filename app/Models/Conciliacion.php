<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Fase 35: un lote de conciliación. Guarda la propuesta (qué cerró, qué falta, qué cargar) y, si se asentó, qué se escribió y cómo se deshace.
class Conciliacion extends Model
{
    use BelongsToBusiness;

    protected $table = 'conciliaciones';
    protected $fillable = ['business_id', 'user_id', 'tipo', 'origen', 'cuenta_fondos_id', 'banco_id', 'archivo', 'desde', 'hasta', 'resumen', 'resultado', 'control', 'estado', 'asentado', 'probado_en', 'asentado_en'];
    protected $casts = ['desde' => 'date', 'hasta' => 'date', 'resumen' => 'array', 'resultado' => 'array', 'control' => 'array', 'asentado' => 'array', 'probado_en' => 'datetime', 'asentado_en' => 'datetime'];

    public const ORIGENES = ['fiserv' => 'Fiserv (Posnet)', 'naranjax' => 'Naranja X', 'gocuotas' => 'GOcuotas', 'mercadopago' => 'Mercado Pago', 'nave' => 'NAVE', 'payway' => 'Payway (Prisma)', 'getnet' => 'Getnet', 'modo' => 'MODO', 'otro' => 'Otra procesadora'];
    public const SIN_LECTOR = ['payway', 'getnet', 'modo'];

    public function cuenta(): BelongsTo { return $this->belongsTo(CuentaFondos::class, 'cuenta_fondos_id'); }
    public function banco(): BelongsTo { return $this->belongsTo(CuentaFondos::class, 'banco_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
