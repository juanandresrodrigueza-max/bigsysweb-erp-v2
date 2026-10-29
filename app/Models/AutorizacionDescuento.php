<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// El OK del encargado para un descuento por encima del tope: 15 minutos, una sola venta, atado al vendedor, al cliente y al %.
class AutorizacionDescuento extends Model
{
    use BelongsToBusiness;

    public $timestamps = false;
    protected $table = 'autorizaciones_descuento';
    protected $fillable = ['business_id', 'user_id', 'autorizado_por', 'contact_id', 'pct', 'token', 'vence_at', 'usada_at', 'comprobante_id', 'created_at'];
    protected $casts = ['pct' => 'float', 'vence_at' => 'datetime', 'usada_at' => 'datetime', 'created_at' => 'datetime'];

    public const MINUTOS = 15;

    public function vendedor(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function encargado(): BelongsTo { return $this->belongsTo(User::class, 'autorizado_por'); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function comprobante(): BelongsTo { return $this->belongsTo(Comprobante::class); }

    public function vigente(): bool { return ! $this->usada_at && $this->vence_at->isFuture(); }
}
