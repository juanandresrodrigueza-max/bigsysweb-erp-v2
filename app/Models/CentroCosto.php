<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Fase 96: centro de costo (sucursal, área, línea de negocio, obra…). Se imputa en gastos y compras y se compara contra el presupuesto del año.
class CentroCosto extends Model
{
    use BelongsToBusiness;

    protected $table = 'centros_costo';
    protected $fillable = ['business_id', 'nombre', 'codigo', 'activo'];
    protected $casts = ['activo' => 'boolean'];

    public function presupuestos(): HasMany { return $this->hasMany(PresupuestoCentro::class); }

    public static function activos(): array
    {
        return static::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'codigo'])->map(fn($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'codigo' => $c->codigo])->all();
    }
}
