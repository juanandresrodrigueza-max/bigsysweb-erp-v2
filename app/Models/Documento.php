<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

// Archivo adjunto a un cliente, proveedor o artículo. Se guarda en el disco privado y se baja con permiso.
class Documento extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'user_id', 'adjuntable_type', 'adjuntable_id', 'nombre', 'archivo', 'mime', 'tamano', 'fecha', 'notas'];
    protected $casts = ['fecha' => 'date'];

    // tipo de la URL => modelo
    public const TIPOS = ['contacto' => Contact::class, 'articulo' => Product::class];

    public function adjuntable(): MorphTo { return $this->morphTo(); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function datos(): array
    {
        return ['id' => $this->id, 'nombre' => $this->nombre, 'mime' => $this->mime, 'tamano' => (int) $this->tamano, 'fecha' => $this->fecha?->toDateString(), 'notas' => $this->notas,
            'subido' => $this->created_at?->format('d/m/Y H:i'), 'usuario' => $this->user?->name, 'imagen' => str_starts_with((string) $this->mime, 'image/'), 'url' => "/documentos/{$this->id}/descargar"];
    }
}
