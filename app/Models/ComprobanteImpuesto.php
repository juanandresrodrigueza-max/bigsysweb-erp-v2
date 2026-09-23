<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComprobanteImpuesto extends Model
{
    public $timestamps = false;
    protected $fillable = ['comprobante_id', 'tipo', 'base', 'alicuota', 'monto'];

    // Nombre corto del tributo: jurisdicción de IIBB, IVA o GAN (percepción de Ganancias).
    public static function etiqueta(string $tipo): string
    {
        return match (true) {
            $tipo === 'perc_iva' => 'IVA',
            $tipo === 'perc_ganancias' => 'GAN',
            $tipo === 'iibb' => 'ARBA',
            str_starts_with($tipo, 'iibb_') => strtoupper(substr($tipo, 5)),
            default => strtoupper($tipo),
        };
    }

    public static function descripcion(string $tipo): string
    {
        return match (true) {
            $tipo === 'perc_iva' => 'Percepción IVA RG 2408',
            $tipo === 'perc_ganancias' => 'Percepción Ganancias',
            str_starts_with($tipo, 'iibb') => 'Percepción IIBB ' . self::etiqueta($tipo),
            default => $tipo,
        };
    }

    public function comprobante(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Comprobante::class); }
}
