<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Padrón de alícuotas de IIBB por CUIT (ARBA, AGIP, etc.). Es global: lo comparten todas las empresas.
class PadronIibb extends Model
{
    protected $table = 'padrones_iibb';
    protected $fillable = ['jurisdiccion', 'cuit', 'alic_percepcion', 'alic_retencion', 'desde', 'hasta', 'fuente'];
    protected $casts = ['alic_percepcion' => 'decimal:3', 'alic_retencion' => 'decimal:3', 'desde' => 'date', 'hasta' => 'date'];

    public const JURISDICCIONES = ['ARBA' => 'Buenos Aires (ARBA)', 'AGIP' => 'CABA (AGIP)', 'CBA' => 'Córdoba', 'SFE' => 'Santa Fe', 'MZA' => 'Mendoza', 'TUC' => 'Tucumán', 'CM' => 'Convenio Multilateral'];

    public static function buscar(?string $cuit, string $jurisdiccion): ?self
    {
        $cuit = preg_replace('/\D/', '', (string) $cuit);
        if (strlen($cuit) !== 11) return null;
        return self::where('jurisdiccion', $jurisdiccion)->where('cuit', $cuit)->where(fn($q) => $q->whereNull('hasta')->orWhere('hasta', '>=', today()))->first();
    }
}
