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

    public const CONDICIONES_IVA = ['Responsable Inscripto', 'Monotributista', 'Exento', 'Consumidor Final', 'No Responsable'];

    protected $fillable = [
        'business_id', 'type', 'tipo_cliente_id', 'name', 'email', 'phone', 'mobile', 'document_type', 'document', 'cuit',
        'condicion_iva', 'address', 'city', 'province', 'postal_code', 'credit_limit', 'lista_precios', 'dias_pago',
        'descuento', 'percepcion_iibb', 'balance', 'is_active', 'notes', 'crm_external_id',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2', 'balance' => 'decimal:2', 'descuento' => 'decimal:2',
        'is_active' => 'boolean', 'percepcion_iibb' => 'boolean',
    ];

    public function tipoCliente(): BelongsTo { return $this->belongsTo(TipoCliente::class, 'tipo_cliente_id'); }
    public function sales(): HasMany { return $this->hasMany(Sale::class); }
    public function comprobantes(): HasMany { return $this->hasMany(Comprobante::class); }
    public function cobros(): HasMany { return $this->hasMany(Cobro::class); }
    public function cuentaCorriente(): HasMany { return $this->hasMany(CuentaCorriente::class); }
    public function acopios(): HasMany { return $this->hasMany(Acopio::class); }
    public function pagos(): HasMany { return $this->hasMany(Pago::class); }
    public function retenciones(): HasMany { return $this->hasMany(Retencion::class); }

    public function deudaVencidaProveedor(): float
    {
        return (float) $this->comprobantes()->pendientesPago()->whereDate('fecha_vto', '<', today())->sum('saldo');
    }

    public function scopeCustomers(Builder $q): Builder { return $q->whereIn('type', ['customer', 'both']); }
    public function scopeSuppliers(Builder $q): Builder { return $q->whereIn('type', ['supplier', 'both']); }

    public function esConsumidorFinal(): bool { return ! $this->cuit || $this->condicion_iva === 'Consumidor Final'; }

    // Tipo de factura según condición IVA de la empresa y del cliente.
    public static function letraPara(Business $business, ?Contact $contact): string
    {
        $emp = $business->condicion_iva ?? 'Responsable Inscripto';
        if ($emp === 'Responsable Inscripto') {
            return ($contact && $contact->condicion_iva === 'Responsable Inscripto') ? 'A' : 'B';
        }
        return 'C';
    }

    public function deudaVencida(): float
    {
        return (float) $this->comprobantes()->pendientesCobro()->whereDate('fecha_vto', '<', today())->sum('saldo');
    }
}
