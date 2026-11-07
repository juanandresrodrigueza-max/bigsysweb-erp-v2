<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransferenciaStock extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    protected $table = 'transferencias_stock';
    protected $fillable = ['business_id', 'business_location_id', 'numero', 'fecha', 'origen_id', 'destino_id', 'user_id', 'estado', 'notas', 'enviada_en', 'recibida_en', 'recibida_por', 'token', 'tipo', 'contact_id', 'pedido_id'];
    protected $casts = ['fecha' => 'date', 'enviada_en' => 'datetime', 'recibida_en' => 'datetime'];

    public function origen(): BelongsTo { return $this->belongsTo(Deposito::class, 'origen_id'); }
    public function destino(): BelongsTo { return $this->belongsTo(Deposito::class, 'destino_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function contacto(): BelongsTo { return $this->belongsTo(Contact::class, 'contact_id'); }
    public function items(): HasMany { return $this->hasMany(TransferenciaStockItem::class, 'transferencia_id'); }

    public function numeroFormateado(): string { return sprintf(match ($this->tipo) { 'pedido' => 'OP %06d', 'proveedor' => 'DP %06d', default => 'TR %06d' }, $this->numero); }
}
