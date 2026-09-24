<?php

namespace App\Support;

// Jurisdicciones de Ingresos Brutos con su código de Convenio Multilateral (COMARB), el que piden SIFERE y SIRCAR.
class JurisdiccionesIibb
{
    public const LISTA = [
        'AGIP' => ['901', 'Ciudad de Buenos Aires'], 'ARBA' => ['902', 'Buenos Aires'], 'CAT' => ['903', 'Catamarca'], 'CBA' => ['904', 'Córdoba'],
        'CTES' => ['905', 'Corrientes'], 'CHACO' => ['906', 'Chaco'], 'CHUBUT' => ['907', 'Chubut'], 'ER' => ['908', 'Entre Ríos'], 'FSA' => ['909', 'Formosa'],
        'JUJUY' => ['910', 'Jujuy'], 'LPAMPA' => ['911', 'La Pampa'], 'LRIOJA' => ['912', 'La Rioja'], 'MZA' => ['913', 'Mendoza'], 'MNES' => ['914', 'Misiones'],
        'NQN' => ['915', 'Neuquén'], 'RNEGRO' => ['916', 'Río Negro'], 'SALTA' => ['917', 'Salta'], 'SJUAN' => ['918', 'San Juan'], 'SLUIS' => ['919', 'San Luis'],
        'SCRUZ' => ['920', 'Santa Cruz'], 'SFE' => ['921', 'Santa Fe'], 'SDE' => ['922', 'Santiago del Estero'], 'TDF' => ['923', 'Tierra del Fuego'], 'TUC' => ['924', 'Tucumán'],
    ];

    // Acepta la sigla (ARBA), el código (902) o el nombre de la provincia; devuelve el código de 3 dígitos o null.
    public static function codigo(?string $j): ?string
    {
        $j = trim((string) $j); if ($j === '') return null;
        if (preg_match('/^9\d\d$/', $j)) return $j;
        $u = strtoupper(\Illuminate\Support\Str::ascii($j));
        if (isset(self::LISTA[$u])) return self::LISTA[$u][0];
        $alias = ['CABA' => 'AGIP', 'CAPITAL' => 'AGIP', 'BA' => 'ARBA', 'BSAS' => 'ARBA', 'CORDOBA' => 'CBA', 'SANTA FE' => 'SFE', 'SF' => 'SFE', 'API' => 'SFE', 'MENDOZA' => 'MZA', 'ATM' => 'MZA', 'TUCUMAN' => 'TUC'];
        if (isset($alias[$u])) return self::LISTA[$alias[$u]][0];
        foreach (self::LISTA as [$cod, $nom]) if (strtoupper(\Illuminate\Support\Str::ascii($nom)) === $u) return $cod;
        return null;
    }

    public static function opciones(): array
    {
        return collect(self::LISTA)->map(fn($v, $k) => ['sigla' => $k, 'codigo' => $v[0], 'nombre' => $v[1]])->values()->all();
    }
}
