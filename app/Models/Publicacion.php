<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Un producto en una tienda: publicado desde acá (el ERP manda precio y stock) o ya existente y atado a un artículo (sólo para facturar),
// y adoptado si además se le manda precio y stock.
class Publicacion extends Model
{
    use BelongsToBusiness;

    protected $table = 'publicaciones';
    protected $fillable = ['business_id', 'canal_id', 'product_id', 'external_id', 'variante_id', 'titulo', 'sku_remoto', 'precio_remoto', 'stock_remoto', 'lista', 'categoria_id', 'origen', 'adoptada', 'con_variantes', 'estado', 'foto_url', 'permalink', 'datos', 'ultimo_error', 'sync_at'];
    protected $casts = ['precio_remoto' => 'float', 'adoptada' => 'boolean', 'con_variantes' => 'boolean', 'datos' => 'array', 'sync_at' => 'datetime'];

    public function canal(): BelongsTo { return $this->belongsTo(Canal::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    // Se le manda precio y stock si se publicó desde acá o si se adoptó; nunca a una con variantes.
    public function sincronizable(): bool { return ($this->origen === 'publicada' || $this->adoptada) && ! $this->con_variantes && $this->product_id && $this->estado !== 'cerrada'; }
}
