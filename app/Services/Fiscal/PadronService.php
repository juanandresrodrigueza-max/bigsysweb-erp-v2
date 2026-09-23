<?php

namespace App\Services\Fiscal;

use App\Models\PadronIibb;
use Illuminate\Support\Facades\DB;

// Importa padrones de IIBB (ARBA, AGIP y otros) desde el archivo oficial o un CSV simple: CUIT;percepción;retención.
class PadronService
{
    public function importar(string $path, string $jurisdiccion, ?string $fuente = null): array
    {
        $fh = fopen($path, 'r');
        if (! $fh) return ['error' => 'No se pudo abrir el archivo'];
        $n = 0; $leidas = 0; $lote = [];
        DB::beginTransaction();
        try {
            while (($linea = fgets($fh)) !== false) {
                $leidas++;
                $linea = trim($linea);
                if ($linea === '') continue;
                $campos = preg_split('/[;,\t|]/', $linea);
                $cuit = null; $decimales = []; $fechas = [];
                foreach ($campos as $c) {
                    $c = trim($c);
                    if (preg_match('/^\d{11}$/', $c)) { $cuit ??= $c; continue; }
                    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $c)) { $fechas[] = \Carbon\Carbon::createFromFormat('d/m/Y', $c); continue; }
                    if (preg_match('/^\d{1,3}([.,]\d{1,3})?$/', $c) && ! preg_match('/^\d{4,}$/', $c)) $decimales[] = (float) str_replace(',', '.', $c);
                }
                if (! $cuit || ! $decimales) continue;
                // Formato ARBA: ...;CUIT;...;percepción;retención  (los dos últimos decimales). CSV simple: CUIT;perc;ret.
                $perc = $decimales[count($decimales) >= 2 ? count($decimales) - 2 : 0];
                $ret = $decimales[count($decimales) - 1];
                $lote[$cuit] = ['jurisdiccion' => $jurisdiccion, 'cuit' => $cuit, 'alic_percepcion' => $perc, 'alic_retencion' => $ret, 'desde' => $fechas[0] ?? null, 'hasta' => $fechas[1] ?? null, 'fuente' => $fuente, 'created_at' => now(), 'updated_at' => now()];
                if (count($lote) >= 500) { PadronIibb::upsert(array_values($lote), ['jurisdiccion', 'cuit'], ['alic_percepcion', 'alic_retencion', 'desde', 'hasta', 'fuente', 'updated_at']); $n += count($lote); $lote = []; }
            }
            if ($lote) { PadronIibb::upsert(array_values($lote), ['jurisdiccion', 'cuit'], ['alic_percepcion', 'alic_retencion', 'desde', 'hasta', 'fuente', 'updated_at']); $n += count($lote); }
            DB::commit();
        } catch (\Throwable $e) { DB::rollBack(); return ['error' => $e->getMessage()]; }
        fclose($fh);
        return ['leidas' => $leidas, 'importadas' => $n];
    }
}
