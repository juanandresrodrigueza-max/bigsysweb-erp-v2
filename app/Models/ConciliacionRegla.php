<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Fase 99: una regla de conciliación: "si el renglón del extracto dice X, hacé Y" (registrar el gasto en tal categoría, ignorarlo, o hacerle recibo a tal cliente).
class ConciliacionRegla extends Model
{
    use BelongsToBusiness;

    protected $table = 'conciliacion_reglas';
    protected $fillable = ['business_id', 'cuenta_fondos_id', 'patron', 'sentido', 'accion', 'expense_category_id', 'contact_id', 'concepto', 'veces', 'ultima_vez', 'creada_por'];
    protected $casts = ['ultima_vez' => 'datetime'];

    public const ACCIONES = ['gasto' => 'Registrar como gasto', 'ignorar' => 'Ignorar (no es del sistema)', 'recibo' => 'Recibo del cliente'];

    public function categoria() { return $this->belongsTo(ExpenseCategory::class, 'expense_category_id'); }
    public function cliente() { return $this->belongsTo(Contact::class, 'contact_id'); }
    public function cuenta() { return $this->belongsTo(CuentaFondos::class, 'cuenta_fondos_id'); }
}
