<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Una parada del vendedor en la calle: llegó, no vendió (con motivo) o vendió. Sella la ubicación en ese momento.
class Visita extends Model
{
    use BelongsToBusiness;

    public $timestamps = false;
    protected $fillable = ['business_id', 'contact_id', 'vendedor_id', 'user_id', 'tipo', 'motivo', 'nota', 'lat', 'lng', 'comprobante_id', 'created_at'];
    protected $casts = ['created_at' => 'datetime', 'lat' => 'float', 'lng' => 'float'];

    public const MOTIVOS = ['cerrado' => 'Estaba cerrado', 'sin_encargado' => 'No estaba el encargado', 'tiene_mercaderia' => 'Todavía tiene mercadería', 'no_quiso' => 'No quiso comprar', 'no_pudo_pagar' => 'No pudo pagar', 'otro' => 'Otro'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function vendedor(): BelongsTo { return $this->belongsTo(Vendedor::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
