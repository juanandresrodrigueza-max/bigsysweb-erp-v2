<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Lo que se aprende de cada proveedor: su código o su descripción → nuestro artículo, y cuántas unidades trae el bulto.
class ProveedorArticulo extends Model
{
    use BelongsToBusiness;

    protected $table = 'proveedor_articulos';
    protected $fillable = ['business_id', 'contact_id', 'codigo', 'descripcion', 'product_id', 'unidades_bulto', 'ultimo_costo', 'ultima_fecha'];
    protected $casts = ['ultima_fecha' => 'date', 'ultimo_costo' => 'float'];

    public function contact(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Contact::class); }
    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Product::class); }

    public static function normalizar(string $t): string { return AliasArticulo::normalizar(preg_replace('/\s*\(b:\s*\d+\)\s*/i', ' ', $t)); }
}
