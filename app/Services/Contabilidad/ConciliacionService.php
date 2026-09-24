<?php

namespace App\Services\Contabilidad;

use App\Models\AuditLog;
use App\Models\CuentaFondos;
use App\Models\ExtractoBancario;
use App\Models\ExtractoItem;
use App\Models\MovimientoFondos;
use App\Services\Fondos\FondosService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Conciliación bancaria: importa el extracto del home banking y lo cruza con los movimientos del sistema.
class ConciliacionService
{
    public function __construct(private FondosService $fondos) {}

    // Importa el extracto en cualquier formato (PDF, Excel, CSV, texto): el lector lo normaliza y acá se guarda y se concilia.
    public function importar(CuentaFondos $cuenta, string $contenido, ?string $nombreArchivo = null): ExtractoBancario
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ext'); file_put_contents($tmp, $contenido);
        try { return $this->importarArchivo($cuenta, $tmp, $nombreArchivo ?: 'extracto.csv'); } finally { @unlink($tmp); }
    }

    public function importarArchivo(CuentaFondos $cuenta, string $path, string $nombreArchivo): ExtractoBancario
    {
        $filas = app(ExtractoLectorService::class)->leer($path, $nombreArchivo);
        return DB::transaction(function () use ($cuenta, $filas, $nombreArchivo) {
            $ext = ExtractoBancario::create(['business_id' => $cuenta->business_id, 'cuenta_fondos_id' => $cuenta->id, 'user_id' => Auth::id(), 'archivo' => $nombreArchivo]);
            $n = 0; $fechas = []; $saldoFinal = null;
            foreach ($filas as $f) {
                if (ExtractoItem::where('cuenta_fondos_id', $cuenta->id)->where('fecha', $f['fecha'])->where('monto', $f['monto'])->where('descripcion', mb_substr($f['descripcion'], 0, 250))->exists()) continue;
                ExtractoItem::create(['extracto_id' => $ext->id, 'business_id' => $cuenta->business_id, 'cuenta_fondos_id' => $cuenta->id, 'fecha' => $f['fecha'], 'descripcion' => mb_substr($f['descripcion'], 0, 250), 'referencia' => $f['referencia'] ? mb_substr((string) $f['referencia'], 0, 80) : null, 'monto' => $f['monto'], 'saldo' => $f['saldo']]);
                $n++; $fechas[] = $f['fecha']; if ($f['saldo'] !== null) $saldoFinal = $f['saldo'];
            }
            if (! $n) throw ValidationException::withMessages(['archivo' => 'No había renglones nuevos para importar (o ya estaban cargados).']);
            $ext->update(['items' => $n, 'desde' => min($fechas), 'hasta' => max($fechas), 'saldo_final' => $saldoFinal]);
            AuditLog::registrar('crear', $ext, "Importó extracto de {$cuenta->nombre} ({$nombreArchivo}): {$n} renglones");
            $this->conciliarAutomatico($cuenta);
            return $ext->fresh();
        });
    }

    // Cruza cada renglón pendiente con un movimiento del sistema: mismo importe y sentido, fecha a ±4 días; si hay varios, el de fecha más cercana.
    public function conciliarAutomatico(CuentaFondos $cuenta): int
    {
        $n = 0;
        $pendientes = ExtractoItem::where('cuenta_fondos_id', $cuenta->id)->where('estado', 'pendiente')->orderBy('fecha')->get();
        $usados = [];
        foreach ($pendientes as $it) {
            $monto = (float) $it->monto;
            $cands = MovimientoFondos::where('cuenta_fondos_id', $cuenta->id)->where('conciliado', false)->whereNotIn('id', $usados)
                ->whereBetween('fecha', [$it->fecha->copy()->subDays(4)->toDateString(), $it->fecha->copy()->addDays(4)->toDateString()])
                ->where($monto >= 0 ? 'ingreso' : 'egreso', round(abs($monto), 2))->get();
            if ($cands->isEmpty()) continue;
            // Referencia coincidente pesa más que la fecha.
            $mejor = $cands->sortBy(fn($m) => [$it->referencia && $m->referencia && str_contains($m->referencia, $it->referencia) ? 0 : 1, abs($m->fecha->diffInDays($it->fecha))])->first();
            $this->vincular($it, $mejor, 'auto');
            $usados[] = $mejor->id; $n++;
        }
        return $n;
    }

    public function vincular(ExtractoItem $it, MovimientoFondos $m, string $tipo = 'manual'): void
    {
        abort_if($m->cuenta_fondos_id !== $it->cuenta_fondos_id, 422, 'El movimiento es de otra cuenta.');
        abort_if(abs(((float) $m->ingreso - (float) $m->egreso) - (float) $it->monto) > 0.01, 422, 'Los importes no coinciden.');
        $it->update(['estado' => 'conciliado', 'match' => $tipo, 'movimiento_fondos_id' => $m->id]);
        $m->update(['conciliado' => true, 'conciliado_en' => now()]);
    }

    public function desvincular(ExtractoItem $it): void
    {
        if ($it->movimiento_fondos_id) MovimientoFondos::where('id', $it->movimiento_fondos_id)->update(['conciliado' => false, 'conciliado_en' => null]);
        $it->update(['estado' => 'pendiente', 'match' => null, 'movimiento_fondos_id' => null]);
    }

    // El banco cobró algo que no estaba en el sistema (comisión, impuesto, interés): se crea el movimiento y queda conciliado.
    public function registrarDesdeExtracto(ExtractoItem $it, ?int $categoriaId, ?string $concepto = null): MovimientoFondos
    {
        $cuenta = CuentaFondos::findOrFail($it->cuenta_fondos_id);
        $monto = (float) $it->monto;
        $m = $this->fondos->registrar($cuenta, ['fecha' => $it->fecha->toDateString(), 'origen' => $monto >= 0 ? 'ingreso' : 'gasto', 'expense_category_id' => $monto < 0 ? $categoriaId : null, 'concepto' => $concepto ?: $it->descripcion, 'ingreso' => max(0, $monto), 'egreso' => max(0, -$monto), 'referencia' => $it->referencia]);
        $this->vincular($it, $m, 'manual');
        app(ContabilidadService::class)->contabilizar($m);
        return $m;
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s, " \t\"'"));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', '.' => '', '_' => ' ']);
        return preg_replace('/\s+/', ' ', $s);
    }

    private function num(string $s): float
    {
        $s = trim(str_replace(['$', ' ', 'ARS'], '', $s));
        if ($s === '' || $s === '-') return 0;
        // 1.234,56 (AR) vs 1,234.56 (US): el último separador es el decimal.
        $ultComa = strrpos($s, ','); $ultPunto = strrpos($s, '.');
        if ($ultComa !== false && ($ultPunto === false || $ultComa > $ultPunto)) $s = str_replace('.', '', $s) and $s = str_replace(',', '.', $s);
        else $s = str_replace(',', '', $s);
        return (float) $s;
    }

    private function fecha(string $s): ?string
    {
        $s = trim($s, " \"'");
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd.m.Y', 'Y/m/d', 'd/m/Y H:i', 'Y-m-d H:i:s'] as $f) {
            try { $d = \Carbon\Carbon::createFromFormat($f, $s); if ($d) return $d->toDateString(); } catch (\Throwable) {}
        }
        return null;
    }
}
