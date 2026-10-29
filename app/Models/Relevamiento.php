<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Foto tomada en la visita: la góndola, el precio de la competencia, el stock del cliente.
class Relevamiento extends Model
{
    use BelongsToBusiness;

    public $timestamps = false;
    protected $fillable = ['business_id', 'contact_id', 'vendedor_id', 'user_id', 'tipo', 'nota', 'foto', 'lat', 'lng', 'created_at'];
    protected $casts = ['created_at' => 'datetime', 'lat' => 'float', 'lng' => 'float'];

    public const TIPOS = ['gondola' => 'Góndola / exhibición', 'competencia' => 'Precio de la competencia', 'stock' => 'Stock del cliente', 'otro' => 'Otra cosa'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function vendedor(): BelongsTo { return $this->belongsTo(Vendedor::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
