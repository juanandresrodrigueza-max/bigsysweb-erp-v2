<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Previsión: un gasto o ingreso que se repite todos los meses o cada X meses (alquiler, seguro, monotributo, cuota del auto, un abono que cobramos).
class Prevision extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    protected $table = 'previsiones';
    protected $fillable = ['business_id', 'business_location_id', 'tipo', 'descripcion', 'monto', 'expense_category_id', 'contact_id', 'cuenta_fondos_id', 'cada_meses', 'dia', 'desde', 'hasta', 'proximo', 'registrar_auto', 'avisar_dias', 'activo', 'notas', 'ultimo_registrado_en'];
    protected $casts = ['monto' => 'decimal:2', 'desde' => 'date', 'hasta' => 'date', 'proximo' => 'date', 'registrar_auto' => 'boolean', 'activo' => 'boolean', 'ultimo_registrado_en' => 'datetime'];

    public const FRECUENCIAS = [1 => 'Todos los meses', 2 => 'Cada 2 meses', 3 => 'Cada 3 meses', 4 => 'Cada 4 meses', 6 => 'Cada 6 meses', 12 => 'Una vez al año'];

    public function categoria(): BelongsTo { return $this->belongsTo(ExpenseCategory::class, 'expense_category_id'); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function cuenta(): BelongsTo { return $this->belongsTo(CuentaFondos::class, 'cuenta_fondos_id'); }
    public function movimientos(): HasMany { return $this->hasMany(MovimientoFondos::class, 'prevision_id'); }

    // Primer vencimiento a partir de una fecha (inclusive), respetando desde/hasta y el día del mes.
    public function calcularProximo(?Carbon $apartirDe = null): ?Carbon
    {
        $apartirDe = ($apartirDe ?? today())->copy()->startOfDay();
        $f = $this->desde->copy()->startOfMonth();
        for ($i = 0; $i < 240; $i++) {
            $cand = $f->copy()->day(min((int) $this->dia, $f->daysInMonth));
            if ($this->hasta && $cand->gt($this->hasta)) return null;
            if ($cand->gte($apartirDe) && $cand->gte($this->desde)) return $cand;
            $f->addMonths(max(1, (int) $this->cada_meses));
        }
        return null;
    }

    // Vencimientos dentro de un rango (para el cash flow).
    public function vencimientosEntre(Carbon $desde, Carbon $hasta): array
    {
        $out = []; $f = $this->calcularProximo($desde);
        while ($f && $f->lte($hasta)) { $out[] = $f; $f = $this->calcularProximo($f->copy()->addDay()); }
        return $out;
    }

    public function frecuenciaLabel(): string { return self::FRECUENCIAS[(int) $this->cada_meses] ?? "Cada {$this->cada_meses} meses"; }
}
