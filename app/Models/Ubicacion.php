<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Lugar físico dentro de un depósito, identificado por un código de barras (pasillo-estante-nivel).
class Ubicacion extends Model
{
    use BelongsToBusiness;

    protected $table = 'ubicaciones';
    protected $fillable = ['business_id', 'deposito_id', 'codigo', 'pasillo', 'estante', 'nivel', 'tipo', 'orden', 'activa', 'notas'];
    protected $casts = ['activa' => 'boolean'];

    public const TIPOS = ['estanteria' => 'Estantería', 'piso' => 'Piso / pallet', 'frio' => 'Cámara de frío', 'cuarentena' => 'Cuarentena', 'recepcion' => 'Recepción', 'despacho' => 'Despacho'];

    public function deposito(): BelongsTo { return $this->belongsTo(Deposito::class); }
    public function stocks(): HasMany { return $this->hasMany(UbicacionStock::class)->where('cantidad', '>', 0); }

    public static function normalizar(string $codigo): string { return strtoupper(preg_replace('/\s+/', '', trim($codigo))); }
}
