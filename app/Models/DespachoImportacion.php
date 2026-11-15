<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Fase 97: un despacho de importación: la mercadería del exterior con su FOB, los gastos de nacionalizar (flete, seguro,
// derechos, tasa, despachante…) prorrateados por valor, y el costo nacionalizado por unidad que entra al stock.
class DespachoImportacion extends Model
{
    use BelongsToBusiness;

    protected $table = 'despachos_importacion';
    protected $fillable = ['business_id', 'business_location_id', 'user_id', 'contact_id', 'comprobante_id', 'deposito_id', 'numero', 'despacho', 'fecha', 'moneda', 'cotizacion', 'estado', 'fob_total', 'gastos_total', 'credito_fiscal', 'costo_total', 'notas', 'nacionalizado_en'];
    protected $casts = ['fecha' => 'date', 'cotizacion' => 'decimal:4', 'fob_total' => 'decimal:2', 'gastos_total' => 'decimal:2', 'credito_fiscal' => 'decimal:2', 'costo_total' => 'decimal:2', 'nacionalizado_en' => 'datetime'];

    public const ESTADOS = ['borrador' => 'En curso', 'nacionalizado' => 'Nacionalizado', 'anulado' => 'Anulado'];
    // Qué gastos van al costo del producto y cuáles son crédito fiscal (IVA, percepciones).
    public const GASTOS = [
        'flete' => ['label' => 'Flete internacional', 'al_costo' => true], 'seguro' => ['label' => 'Seguro', 'al_costo' => true], 'derechos' => ['label' => 'Derechos de importación', 'al_costo' => true],
        'tasa_estadistica' => ['label' => 'Tasa de estadística', 'al_costo' => true], 'despachante' => ['label' => 'Despachante', 'al_costo' => true], 'deposito_fiscal' => ['label' => 'Depósito fiscal / puerto', 'al_costo' => true],
        'flete_interno' => ['label' => 'Flete interno', 'al_costo' => true], 'otros' => ['label' => 'Otros gastos', 'al_costo' => true],
        'iva' => ['label' => 'IVA del despacho', 'al_costo' => false], 'percepcion_iva' => ['label' => 'Percepción de IVA', 'al_costo' => false], 'percepcion_ganancias' => ['label' => 'Percepción de Ganancias', 'al_costo' => false],
    ];

    public function items(): HasMany { return $this->hasMany(DespachoItem::class, 'despacho_id')->orderBy('orden'); }
    public function gastos(): HasMany { return $this->hasMany(DespachoGasto::class, 'despacho_id'); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function deposito(): BelongsTo { return $this->belongsTo(Deposito::class); }

    public function numeroFormateado(): string { return sprintf('IMP-%05d', $this->numero); }
}
