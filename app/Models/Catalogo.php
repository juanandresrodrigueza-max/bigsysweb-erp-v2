<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

// Catálogo de artículos con precios de una lista (o los de cada cliente) para mandar por link, PDF, mail o WhatsApp.
class Catalogo extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'nombre', 'lista', 'rubros', 'iva_incluido', 'mostrar_stock', 'solo_con_stock', 'mostrar_fotos', 'mostrar_codigo', 'nota', 'token', 'vistas', 'visto_en', 'activo', 'user_id'];
    protected $casts = ['rubros' => 'array', 'iva_incluido' => 'boolean', 'mostrar_stock' => 'boolean', 'solo_con_stock' => 'boolean', 'mostrar_fotos' => 'boolean', 'mostrar_codigo' => 'boolean', 'activo' => 'boolean', 'visto_en' => 'datetime'];

    // Para el envío: el cliente al que se le manda (no se guarda).
    public ?Contact $contact = null;

    protected static function booted(): void
    {
        static::creating(fn(self $c) => $c->token ??= Str::random(32));
    }

    public function business() { return $this->belongsTo(Business::class); }

    // Link público. Con cliente: ve sus precios (lista, pactados y descuentos por rubro).
    public function url(?Contact $c = null): string
    {
        return url('/catalogo/' . $this->token) . ($c ? '?c=' . \App\Http\Controllers\PortalController::token($c) : '');
    }
}
