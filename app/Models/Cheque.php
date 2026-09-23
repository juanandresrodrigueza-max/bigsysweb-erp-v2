<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cheque extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    public const ESTADOS = ['cartera' => 'En cartera', 'depositado' => 'Depositado', 'entregado' => 'Entregado', 'cobrado' => 'Cobrado', 'rechazado' => 'Rechazado', 'anulado' => 'Anulado', 'pagado' => 'Pagado'];

    protected $fillable = ['business_id', 'tipo', 'numero', 'banco', 'emisor', 'cuit_emisor', 'fecha_emision', 'fecha_pago', 'monto', 'echeq', 'estado', 'cobro_id', 'pago_id', 'cuenta_fondos_id', 'contact_id', 'fecha_estado', 'notas'];
    protected $casts = ['fecha_emision' => 'date', 'fecha_pago' => 'date', 'fecha_estado' => 'date', 'monto' => 'decimal:2', 'echeq' => 'boolean'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function cuenta(): BelongsTo { return $this->belongsTo(CuentaFondos::class, 'cuenta_fondos_id'); }
    public function cobro(): BelongsTo { return $this->belongsTo(Cobro::class); }
    public function pago(): BelongsTo { return $this->belongsTo(Pago::class); }

    public function scopeEnCartera(Builder $q): Builder { return $q->where('tipo', 'tercero')->where('estado', 'cartera'); }
    public function scopePropiosPendientes(Builder $q): Builder { return $q->where('tipo', 'propio')->whereIn('estado', ['entregado']); }
}
