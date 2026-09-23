<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Obra o proyecto con costeo: presupuesto vs real, partes diarios y certificación de avance.
class Proyecto extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    public const ESTADOS = ['presupuestado' => 'Presupuestado', 'en_curso' => 'En curso', 'pausado' => 'Pausado', 'terminado' => 'Terminado', 'cancelado' => 'Cancelado'];
    public const TIPOS_PARTE = ['material' => 'Materiales', 'mano_obra' => 'Mano de obra', 'maquinaria' => 'Maquinaria y equipos', 'subcontrato' => 'Subcontratos', 'gasto' => 'Otros gastos'];

    protected $fillable = ['business_id', 'business_location_id', 'contact_id', 'responsable_id', 'codigo', 'nombre', 'descripcion', 'direccion', 'estado', 'fecha_inicio', 'fecha_fin_prevista', 'fecha_fin', 'presupuesto_venta', 'presupuesto_costo', 'avance', 'avance_certificado', 'notas'];
    protected $casts = ['fecha_inicio' => 'date', 'fecha_fin_prevista' => 'date', 'fecha_fin' => 'date', 'presupuesto_venta' => 'decimal:2', 'presupuesto_costo' => 'decimal:2', 'avance' => 'decimal:2', 'avance_certificado' => 'decimal:2'];

    public function contact() { return $this->belongsTo(Contact::class); }
    public function responsable() { return $this->belongsTo(User::class, 'responsable_id'); }
    public function partes() { return $this->hasMany(ProyectoParte::class); }
    public function comprobantes() { return $this->hasMany(Comprobante::class); }
    public function gastos() { return $this->hasMany(Expense::class); }
}
