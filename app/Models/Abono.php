<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Facturación recurrente: abonos, cuotas, alquileres. Cada vencimiento genera una factura.
class Abono extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'contact_id', 'descripcion', 'items', 'condicion', 'frecuencia', 'dia_emision', 'desde', 'hasta', 'meses_excluidos', 'emitir_auto', 'activo', 'cuota_actual', 'proximo', 'ultimo_emitido_en', 'notas'];
    protected $casts = ['items' => 'array', 'meses_excluidos' => 'array', 'emitir_auto' => 'boolean', 'activo' => 'boolean', 'desde' => 'date', 'hasta' => 'date', 'proximo' => 'date', 'ultimo_emitido_en' => 'datetime'];

    public const FRECUENCIAS = ['mensual' => 1, 'bimestral' => 2, 'trimestral' => 3, 'semestral' => 6, 'anual' => 12];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function comprobantes(): HasMany { return $this->hasMany(Comprobante::class); }

    public function importe(): float
    {
        return round(collect($this->items)->sum(fn($i) => (float) $i['cantidad'] * (float) $i['precio_unit'] * (1 + (float) ($i['alicuota_iva'] ?? 21) / 100)), 2);
    }

    // Próxima fecha de emisión a partir de una fecha, saltando meses excluidos y respetando "hasta".
    public function calcularProximo(?\Carbon\Carbon $desde = null): ?\Carbon\Carbon
    {
        $paso = self::FRECUENCIAS[$this->frecuencia] ?? 1;
        $f = ($desde ? $desde->copy()->startOfMonth()->addMonths($paso) : $this->desde->copy()->startOfMonth());
        for ($i = 0; $i < 36; $i++) {
            $cand = $f->copy()->day(min((int) $this->dia_emision, $f->daysInMonth));
            if ($this->hasta && $cand->gt($this->hasta)) return null;
            if (! in_array($cand->month, $this->meses_excluidos ?? [], true) && $cand->gte($this->desde)) return $cand;
            $f->addMonths($paso);
        }
        return null;
    }
}
