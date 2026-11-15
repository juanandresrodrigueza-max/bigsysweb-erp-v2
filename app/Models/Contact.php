<?php
namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use SoftDeletes, BelongsToBusiness;

    // Las condiciones que acepta ARCA para el receptor (RG 5616). «No Responsable» se mantiene por los clientes viejos: hoy es «No Categorizado».
    public const CONDICIONES_IVA = ['Responsable Inscripto', 'Monotributista', 'Monotributista Social', 'Exento', 'Consumidor Final', 'No Categorizado', 'IVA No Alcanzado', 'No Responsable', 'Exterior'];
    // Fase 102: a quiénes un responsable inscripto les factura con letra A (RG 1415 y, desde el 1/7/2021, RG 5003 / ley 27.618 para el monotributo).
    public const RECIBEN_A = ['Responsable Inscripto', 'Monotributista', 'Monotributista Social', 'Monotributista Trabajador Independiente Promovido'];
    public const LEYENDA_MONOTRIBUTO = 'El crédito fiscal discriminado en el presente comprobante, sólo podrá ser computado a efectos del Régimen de Sostenimiento e Inclusión Fiscal para Pequeños Contribuyentes de la Ley Nº 27.618';

    protected $fillable = [
        'business_id', 'type', 'tipo_cliente_id', 'name', 'email', 'phone', 'mobile', 'document_type', 'document', 'cuit',
        'condicion_iva', 'address', 'city', 'province', 'postal_code', 'credit_limit', 'lista_precios', 'dias_pago', 'interes_mora', 'vendedor_id',
        'descuento', 'percepcion_iibb', 'jurisdiccion_iibb', 'alicuota_percepcion_iibb', 'alicuota_retencion_iibb', 'exento_iibb', 'percepcion_iva', 'percepcion_ganancias', 'retiene_ganancias', 'balance', 'is_active', 'notes', 'crm_external_id', 'portal_token', 'puntos', 'pais_codigo', 'cuit_pais', 'id_impositivo', 'recordar_precio', 'codigo', 'cobrador_id', 'visitas', 'lat', 'lng', 'compras_config',
    ];

    protected $casts = ['compras_config' => 'array', 
        'credit_limit' => 'decimal:2', 'balance' => 'decimal:2', 'descuento' => 'decimal:2',
        'is_active' => 'boolean', 'percepcion_iibb' => 'boolean', 'exento_iibb' => 'boolean', 'percepcion_iva' => 'boolean', 'percepcion_ganancias' => 'boolean', 'retiene_ganancias' => 'boolean', 'recordar_precio' => 'boolean', 'visitas' => 'array', 'lat' => 'float', 'lng' => 'float',
    ];

    public function tipoCliente(): BelongsTo { return $this->belongsTo(TipoCliente::class, 'tipo_cliente_id'); }
    public function vendedor(): BelongsTo { return $this->belongsTo(Vendedor::class); }
    public function preciosPactados(): HasMany { return $this->hasMany(PrecioPactado::class); }
    public function sales(): HasMany { return $this->hasMany(Sale::class); }
    public function comprobantes(): HasMany { return $this->hasMany(Comprobante::class); }
    public function cobros(): HasMany { return $this->hasMany(Cobro::class); }
    public function cuentaCorriente(): HasMany { return $this->hasMany(CuentaCorriente::class); }
    public function acopios(): HasMany { return $this->hasMany(Acopio::class); }
    public function personas(): HasMany { return $this->hasMany(ContactoPersona::class)->orderBy('nombre'); }
    public function pagos(): HasMany { return $this->hasMany(Pago::class); }
    public function retenciones(): HasMany { return $this->hasMany(Retencion::class); }

    public function deudaVencidaProveedor(): float
    {
        return (float) $this->comprobantes()->pendientesPago()->where('fecha_vto', '<', today()->toDateString())->sum('saldo');
    }

    public function scopeCustomers(Builder $q): Builder { return $q->whereIn('type', ['customer', 'both']); }
    public function scopeSuppliers(Builder $q): Builder { return $q->whereIn('type', ['supplier', 'both']); }

    // Fase 44.7: condición de IVA que no cierra con el CUIT (CF con CUIT de 11 dígitos, o RI sin CUIT).
    public function scopeIvaDudosa($q)
    {
        return $q->where(fn($w) => $w->where(fn($a) => $a->where('condicion_iva', 'Consumidor Final')->whereRaw("LENGTH(REPLACE(REPLACE(COALESCE(cuit, ''), '-', ''), ' ', '')) = 11"))
            ->orWhere(fn($a) => $a->where('condicion_iva', 'Responsable Inscripto')->where(fn($c) => $c->whereNull('cuit')->orWhere('cuit', ''))));
    }

    public function esConsumidorFinal(): bool { return ! $this->cuit || $this->condicion_iva === 'Consumidor Final'; }

    // Tipo de factura según condición IVA de la empresa y del cliente (RG 1415, anexo IV; RG 5003 para el monotributo; exportación RG 1415 art. 7).
    //   Emisor responsable inscripto: A al responsable inscripto y al monotributista (con la leyenda de la ley 27.618); B al exento, consumidor final,
    //   no categorizado e IVA no alcanzado. Emisor monotributista o exento: C a todos. Cliente del exterior: E, sea quien sea el emisor.
    public static function letraPara(Business $business, ?Contact $contact): string
    {
        return self::letraSegun($business->condicion_iva ?? 'Responsable Inscripto', $contact?->condicionEfectiva());
    }

    // La condición que vale para facturar: sin CUIT es consumidor final aunque la ficha diga otra cosa (la A y la condición 1/6 de ARCA exigen CUIT).
    public function condicionEfectiva(): string
    {
        if ($this->condicion_iva === 'Exterior') return 'Exterior';
        return trim((string) $this->cuit) === '' ? 'Consumidor Final' : ($this->condicion_iva ?: 'Consumidor Final');
    }

    public static function letraSegun(?string $emisor, ?string $receptor): string
    {
        if ($receptor === 'Exterior') return 'E';
        if (($emisor ?? 'Responsable Inscripto') === 'Responsable Inscripto') return in_array($receptor, self::RECIBEN_A, true) ? 'A' : 'B';
        return 'C';
    }

    // La leyenda obligatoria cuando un responsable inscripto le factura con A a un monotributista (RG 1415 art. 15 inc. a, texto RG 5003).
    public static function leyendaPara(string $letra, ?string $receptor): ?string
    {
        return $letra === 'A' && $receptor !== null && str_starts_with($receptor, 'Monotributista') ? self::LEYENDA_MONOTRIBUTO : null;
    }

    public function deudaVencida(): float
    {
        return (float) $this->comprobantes()->pendientesCobro()->where('fecha_vto', '<', today()->toDateString())->sum('saldo');
    }
}
