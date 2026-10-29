<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Cómo va cada rubro a cada tienda: la categoría (de MercadoLibre, de la web), la lista de precios y los datos que pide la categoría.
class RubroCanal extends Model
{
    use BelongsToBusiness;

    protected $table = 'rubros_canal';
    protected $fillable = ['business_id', 'canal_id', 'rubro_id', 'categoria_id', 'categoria_nombre', 'lista', 'atributos'];
    protected $casts = ['atributos' => 'array'];

    public function rubro(): BelongsTo { return $this->belongsTo(Rubro::class); }
    public function canal(): BelongsTo { return $this->belongsTo(Canal::class); }
}
