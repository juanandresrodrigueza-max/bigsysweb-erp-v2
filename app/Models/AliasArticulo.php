<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Lo que el usuario ya confirmó una vez: este título (de una publicación, de un pedido por WhatsApp, de una factura de compra) es este artículo.
class AliasArticulo extends Model
{
    use BelongsToBusiness;

    protected $table = 'alias_articulos';
    protected $fillable = ['business_id', 'texto', 'product_id', 'origen'];

    public static function normalizar(string $t): string { return trim(mb_strtolower(preg_replace('/\s+/', ' ', \Illuminate\Support\Str::ascii($t)))); }
}
