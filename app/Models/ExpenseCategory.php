<?php
namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'name', 'color', 'tipo_costo', 'imputacion'];

    public const TIPOS = ['fijo' => 'Fijo', 'variable' => 'Variable'];
    public const IMPUTACIONES = ['directo' => 'Directo', 'indirecto' => 'Indirecto'];

    // Sugerencia por nombre para que el dueño no tenga que saber contabilidad.
    public static function sugerir(string $nombre): array
    {
        $n = mb_strtolower($nombre);
        $variable = preg_match('/flete|envio|envío|comisi|embalaje|packaging|bolsa|combustible|mercado ?pago|tarjeta|posnet|publicidad|ads/', $n) === 1;
        $directo = preg_match('/flete|envio|envío|comisi|embalaje|packaging|bolsa|mano de obra|subcontrat|material/', $n) === 1;
        return ['tipo_costo' => $variable ? 'variable' : 'fijo', 'imputacion' => $directo ? 'directo' : 'indirecto'];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
