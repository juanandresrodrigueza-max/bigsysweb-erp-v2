<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Cómo factura un proveedor (por CUIT, compartido entre todos los negocios): qué es cada concepto raro del pie de la factura.
class ProveedorRegla extends Model
{
    protected $table = 'proveedor_reglas';
    protected $fillable = ['cuit', 'concepto', 'tratamiento', 'votos'];

    public const TRATAMIENTOS = ['percepcion_iibb' => 'Percepción de Ingresos Brutos', 'percepcion_iva' => 'Percepción de IVA', 'percepcion_ganancias' => 'Percepción de Ganancias', 'descuento' => 'Descuento sobre la factura', 'flete' => 'Flete / gasto (un renglón más)', 'ignorar' => 'No es nada: ignorarlo'];

    public static function normalizar(string $t): string { return mb_substr(AliasArticulo::normalizar(preg_replace('/[\d.,%$]+/', ' ', $t)), 0, 120); }
}
