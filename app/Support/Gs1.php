<?php

namespace App\Support;

// GS1-128 (y GS1 DataMatrix): el código largo del bulto que trae el vencimiento adentro. Devuelve lo que sirve en una recepción:
// el GTIN, el lote, el vencimiento y la cantidad del bulto. Un EAN común devuelve null (no es GS1-128).
class Gs1
{
    private const FIJOS = ['00' => 18, '01' => 14, '02' => 14, '11' => 6, '12' => 6, '13' => 6, '15' => 6, '16' => 6, '17' => 6, '20' => 2];
    private const VARIABLES = ['10' => 20, '21' => 20, '22' => 20, '30' => 8, '37' => 8, '240' => 30, '241' => 30, '400' => 30];

    public static function parse(string $raw): ?array
    {
        $s = trim($raw);
        $s = preg_replace('/^\]?[Cc]1/', '', $s);          // prefijo ]C1 que mandan algunas pistolas
        $s = str_replace(["\x1d", "\u{001d}", '<GS>', '{GS}', '~'], "\x1d", $s);
        $s = ltrim($s, "\x1d");
        if ($s === '' || ! preg_match('/^(01|02|00|10|17|15|21|30|37|11|13|310|240)\d/', $s) || preg_match('/^\d{8}$|^\d{12,14}$/', $s)) return null;
        $out = ['gtin' => null, 'lote' => null, 'vencimiento' => null, 'cantidad' => null, 'serie' => null, 'peso' => null];
        $i = 0; $n = strlen($s); $leyo = false;
        while ($i < $n) {
            if ($s[$i] === "\x1d") { $i++; continue; }
            $ai = null;
            foreach ([2, 3, 4] as $len) { $cand = substr($s, $i, $len); if (isset(self::FIJOS[$cand]) || isset(self::VARIABLES[$cand]) || preg_match('/^31[0-9]\d$/', $cand)) { $ai = $cand; break; } }
            if ($ai === null) return $leyo ? $out : null;
            $i += strlen($ai);
            if (isset(self::FIJOS[$ai])) { $v = substr($s, $i, self::FIJOS[$ai]); $i += self::FIJOS[$ai]; }
            elseif (preg_match('/^31[0-9](\d)$/', $ai, $m)) { $v = substr($s, $i, 6); $i += 6; $out['peso'] = (float) $v / (10 ** (int) $m[1]); $leyo = true; continue; }
            else { $fin = strpos($s, "\x1d", $i); $v = $fin === false ? substr($s, $i) : substr($s, $i, $fin - $i); $v = substr($v, 0, self::VARIABLES[$ai]); $i += strlen($v); }
            $leyo = true;
            switch ($ai) {
                case '01': case '02': $out['gtin'] = self::gtin($v); break;
                case '10': $out['lote'] = $v; break;
                case '21': $out['serie'] = $v; break;
                case '17': case '15': case '12': case '16': if (! $out['vencimiento'] || $ai === '17') $out['vencimiento'] = self::fecha($v); break;
                case '30': case '37': $out['cantidad'] = (float) $v ?: null; break;
            }
        }
        return $leyo && ($out['gtin'] || $out['lote'] || $out['vencimiento']) ? $out : null;
    }

    // El GTIN-14 del bulto suele ser el EAN-13 de la ficha con un indicador adelante: se devuelve como está y también sin el cero.
    public static function gtin(string $v): string
    {
        $v = preg_replace('/\D/', '', $v);
        return strlen($v) === 14 && $v[0] === '0' ? substr($v, 1) : $v;
    }

    // YYMMDD; el día 00 es «fin de mes».
    public static function fecha(string $v): ?string
    {
        if (! preg_match('/^(\d{2})(\d{2})(\d{2})$/', $v, $m)) return null;
        $anio = 2000 + (int) $m[1]; $mes = (int) $m[2]; $dia = (int) $m[3];
        if ($mes < 1 || $mes > 12) return null;
        if ($dia === 0) $dia = (int) date('t', mktime(0, 0, 0, $mes, 1, $anio));
        return checkdate($mes, $dia, $anio) ? sprintf('%04d-%02d-%02d', $anio, $mes, $dia) : null;
    }
}
