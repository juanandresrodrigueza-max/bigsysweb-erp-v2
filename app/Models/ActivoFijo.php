<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Bien de uso: se amortiza mes a mes en forma lineal.
class ActivoFijo extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    public const CATEGORIAS = ['rodados' => ['Rodados', 60], 'maquinaria' => ['Maquinaria y herramientas', 120], 'muebles' => ['Muebles y útiles', 120], 'equipos' => ['Equipos de computación', 36], 'instalaciones' => ['Instalaciones', 120], 'inmuebles' => ['Inmuebles', 600], 'otros' => ['Otros bienes', 60]];
    public const ESTADOS = ['activo' => 'En uso', 'baja' => 'Dado de baja', 'vendido' => 'Vendido'];

    protected $table = 'activos_fijos';
    protected $fillable = ['business_id', 'business_location_id', 'nombre', 'categoria', 'identificacion', 'fecha_alta', 'valor_origen', 'valor_residual', 'vida_util_meses', 'amortizado', 'estado', 'fecha_baja', 'valor_baja', 'comprobante_id', 'proyecto_id', 'notas'];
    protected $casts = ['fecha_alta' => 'date', 'fecha_baja' => 'date', 'valor_origen' => 'decimal:2', 'valor_residual' => 'decimal:2', 'amortizado' => 'decimal:2', 'valor_baja' => 'decimal:2'];

    public function amortizaciones() { return $this->hasMany(ActivoAmortizacion::class); }
    public function comprobante() { return $this->belongsTo(Comprobante::class); }
    public function location() { return $this->belongsTo(BusinessLocation::class, 'business_location_id'); }

    public function cuotaMensual(): float { return $this->vida_util_meses > 0 ? round(((float) $this->valor_origen - (float) $this->valor_residual) / $this->vida_util_meses, 2) : 0; }
    public function valorResidualContable(): float { return round((float) $this->valor_origen - (float) $this->amortizado, 2); }
    public function amortizable(): float { return max(0, round((float) $this->valor_origen - (float) $this->valor_residual - (float) $this->amortizado, 2)); }
    public function mesesAmortizados(): int { return $this->amortizaciones()->count(); }
}
