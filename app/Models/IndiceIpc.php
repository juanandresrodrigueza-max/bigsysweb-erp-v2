<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IndiceIpc extends Model
{
    protected $table = 'indices_ipc';
    protected $fillable = ['periodo', 'valor', 'fuente'];
    protected $casts = ['valor' => 'decimal:4'];

    public static function valor(string $periodo): ?float
    {
        $v = self::where('periodo', $periodo)->value('valor');
        return $v === null ? null : (float) $v;
    }

    // Coeficiente para llevar un importe del período origen al período de cierre.
    public static function coeficiente(string $origen, string $cierre): ?float
    {
        $o = self::valor($origen); $c = self::valor($cierre);
        return $o && $c ? round($c / $o, 6) : null;
    }
}
