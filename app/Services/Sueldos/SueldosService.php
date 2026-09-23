<?php

namespace App\Services\Sueldos;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\CuentaFondos;
use App\Models\Empleado;
use App\Models\Liquidacion;
use App\Models\LiquidacionItem;
use App\Models\SueldoConcepto;
use App\Services\Contabilidad\ContabilidadService;
use App\Services\Fondos\FondosService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Sueldos simplificado: liquida con los conceptos de la empresa (o importa lo que manda el contador), arma el asiento y paga desde fondos.
class SueldosService
{
    public function __construct(private ContabilidadService $conta, private FondosService $fondos) {}

    public function conceptos(Business $b)
    {
        SueldoConcepto::crearDefault($b->id);
        return SueldoConcepto::where('activo', true)->orderBy('orden')->get();
    }

    // Calcula un recibo. $nov: dias, horas_extra_50, horas_extra_100, adicionales, no_rem_extra, anticipos.
    public function calcular(Empleado $e, $conceptos, array $nov, string $tipo = 'mensual', ?Carbon $periodo = null): array
    {
        $periodo ??= today();
        $basico = (float) $e->sueldo_basico;
        $dias = (int) ($nov['dias'] ?? 30);
        $det = [];
        if ($tipo === 'sac') {
            // Aguinaldo: 50% de la mejor remuneración del semestre (simplificado: básico + antigüedad), proporcional a los días trabajados.
            $ant = $conceptos->firstWhere('codigo', 'ANT');
            $mejor = $basico * (1 + ($ant ? (float) $ant->valor * $e->antiguedadAnios($periodo) / 100 : 0));
            $bruto = round($mejor / 2 * min(1, $dias / 180), 2);
            $det[] = ['codigo' => 'SAC', 'nombre' => 'Sueldo anual complementario', 'tipo' => 'haber', 'monto' => $bruto];
        } else {
            $prop = $e->modalidad === 'jornal' ? $dias : min(1, $dias / 30);
            $b = round($basico * $prop, 2);
            $det[] = ['codigo' => 'BAS', 'nombre' => $e->modalidad === 'jornal' ? "Jornales ({$dias} días)" : 'Sueldo básico' . ($dias < 30 ? " ({$dias} días)" : ''), 'tipo' => 'haber', 'monto' => $b];
            $bruto = $b;
            $valorHora = $basico / 200;
            if (($h = (float) ($nov['horas_extra_50'] ?? 0)) > 0) { $m = round($h * $valorHora * 1.5, 2); $bruto += $m; $det[] = ['codigo' => 'HE50', 'nombre' => "Horas extra 50% ({$h} h)", 'tipo' => 'haber', 'monto' => $m]; }
            if (($h = (float) ($nov['horas_extra_100'] ?? 0)) > 0) { $m = round($h * $valorHora * 2, 2); $bruto += $m; $det[] = ['codigo' => 'HE100', 'nombre' => "Horas extra 100% ({$h} h)", 'tipo' => 'haber', 'monto' => $m]; }
            if (($a = (float) ($nov['adicionales'] ?? 0)) > 0) { $bruto += $a; $det[] = ['codigo' => 'ADI', 'nombre' => 'Adicionales / premios', 'tipo' => 'haber', 'monto' => $a]; }
            foreach ($conceptos->where('tipo', 'haber') as $c) {
                $m = $this->monto($c, $b, $bruto, $e, $periodo);
                if ($m > 0) { $bruto += $m; $det[] = ['codigo' => $c->codigo, 'nombre' => $c->nombre, 'tipo' => 'haber', 'monto' => $m]; }
            }
        }
        $noRem = (float) ($nov['no_rem_extra'] ?? 0);
        if ($noRem > 0) $det[] = ['codigo' => 'NR', 'nombre' => 'No remunerativo', 'tipo' => 'no_remunerativo', 'monto' => round($noRem, 2)];
        foreach ($conceptos->where('tipo', 'no_remunerativo') as $c) { $m = $this->monto($c, $basico, $bruto, $e, $periodo); if ($m > 0) { $noRem += $m; $det[] = ['codigo' => $c->codigo, 'nombre' => $c->nombre, 'tipo' => 'no_remunerativo', 'monto' => $m]; } }
        $ded = 0; $contrib = 0;
        foreach ($conceptos->where('tipo', 'deduccion') as $c) { $m = $this->monto($c, $basico, $bruto, $e, $periodo); if ($m > 0) { $ded += $m; $det[] = ['codigo' => $c->codigo, 'nombre' => $c->nombre, 'tipo' => 'deduccion', 'monto' => $m]; } }
        foreach ($conceptos->where('tipo', 'contribucion') as $c) { $m = $this->monto($c, $basico, $bruto, $e, $periodo); if ($m > 0) { $contrib += $m; $det[] = ['codigo' => $c->codigo, 'nombre' => $c->nombre, 'tipo' => 'contribucion', 'monto' => $m]; } }
        $ant = round((float) ($nov['anticipos'] ?? 0), 2);
        if ($ant > 0) $det[] = ['codigo' => 'ANTI', 'nombre' => 'Anticipos ya pagados', 'tipo' => 'deduccion', 'monto' => $ant];
        $neto = round($bruto + $noRem - $ded - $ant, 2);
        return ['dias' => $dias, 'horas_extra_50' => (float) ($nov['horas_extra_50'] ?? 0), 'horas_extra_100' => (float) ($nov['horas_extra_100'] ?? 0), 'adicionales' => (float) ($nov['adicionales'] ?? 0), 'no_rem_extra' => (float) ($nov['no_rem_extra'] ?? 0), 'anticipos' => $ant,
            'bruto' => round($bruto, 2), 'no_rem' => round($noRem, 2), 'deducciones' => round($ded, 2), 'neto' => $neto, 'contribuciones' => round($contrib, 2), 'detalle' => $det];
    }

    private function monto(SueldoConcepto $c, float $basico, float $bruto, Empleado $e, Carbon $periodo): float
    {
        if ($c->modo === 'fijo') return round((float) $c->valor, 2);
        $base = $c->base === 'bruto' ? $bruto : $basico;
        $pct = (float) $c->valor;
        if ($c->codigo === 'ANT') $pct *= $e->antiguedadAnios($periodo); // 1% por año
        return round($base * $pct / 100, 2);
    }

    // Arma o rehace la liquidación en borrador de un período con las novedades por empleado.
    public function liquidar(Business $b, string $periodo, string $tipo, array $novedades, ?string $fecha = null): Liquidacion
    {
        return DB::transaction(function () use ($b, $periodo, $tipo, $novedades, $fecha) {
            $liq = Liquidacion::firstOrNew(['periodo' => $periodo, 'tipo' => $tipo]);
            abort_if($liq->exists && $liq->estado !== 'borrador', 422, 'Esa liquidación ya está confirmada.');
            $liq->fill(['business_id' => $b->id, 'user_id' => Auth::id(), 'fecha' => $fecha ?: Carbon::parse($periodo . '-01')->endOfMonth()->toDateString(), 'estado' => 'borrador', 'importada' => false])->save();
            $liq->items()->delete();
            $conceptos = $this->conceptos($b);
            $per = Carbon::parse($periodo . '-01')->endOfMonth();
            foreach (Empleado::where('activo', true)->orderBy('legajo')->get() as $e) {
                $nov = $novedades[$e->id] ?? [];
                if (! empty($nov['excluir'])) continue;
                $liq->items()->create(['empleado_id' => $e->id] + $this->calcular($e, $conceptos, $nov, $tipo, $per));
            }
            $liq->recalcular();
            AuditLog::registrar($liq->wasRecentlyCreated ? 'crear' : 'editar', $liq, "Liquidación {$liq->periodoLabel()} en borrador");
            return $liq->fresh('items.empleado');
        });
    }

    // Importa lo que manda el contador o el liquidador: CSV con legajo o CUIL; bruto; no remunerativo; deducciones; neto; contribuciones.
    public function importar(Business $b, string $periodo, string $tipo, string $csv): Liquidacion
    {
        return DB::transaction(function () use ($b, $periodo, $tipo, $csv) {
            $liq = Liquidacion::firstOrNew(['periodo' => $periodo, 'tipo' => $tipo]);
            abort_if($liq->exists && $liq->estado !== 'borrador', 422, 'Esa liquidación ya está confirmada.');
            $liq->fill(['business_id' => $b->id, 'user_id' => Auth::id(), 'fecha' => Carbon::parse($periodo . '-01')->endOfMonth()->toDateString(), 'estado' => 'borrador', 'importada' => true])->save();
            $liq->items()->delete();
            $n = 0; $errores = [];
            $sep = substr_count($csv, ';') >= substr_count($csv, ',') ? ';' : ',';
            foreach (preg_split('/\r\n|\r|\n/', trim($csv)) as $i => $linea) {
                if ($linea === '' || ($i === 0 && preg_match('/legajo|cuil|bruto/i', $linea))) continue;
                $c = array_map('trim', str_getcsv($linea, $sep));
                if (count($c) < 5) { $errores[] = 'Línea ' . ($i + 1) . ': faltan columnas'; continue; }
                $num = fn($v) => (float) str_replace(['.', ','], ['', '.'], preg_replace('/[^\d,.\-]/', '', (string) $v)) ?: (float) $v;
                $clave = preg_replace('/\D/', '', $c[0]);
                $e = Empleado::where('legajo', (int) $clave)->first() ?? Empleado::whereRaw("replace(replace(cuil,'-',''),' ','') = ?", [$clave])->first();
                if (! $e) { $errores[] = 'Línea ' . ($i + 1) . ": no encuentro el empleado {$c[0]}"; continue; }
                $bruto = $num($c[1]); $noRem = $num($c[2]); $ded = $num($c[3]); $neto = $num($c[4]); $contrib = isset($c[5]) ? $num($c[5]) : 0;
                $liq->items()->create(['empleado_id' => $e->id, 'dias' => 30, 'bruto' => $bruto, 'no_rem' => $noRem, 'deducciones' => $ded, 'neto' => $neto ?: round($bruto + $noRem - $ded, 2), 'contribuciones' => $contrib,
                    'detalle' => [['codigo' => 'IMP', 'nombre' => 'Remuneración bruta (importada)', 'tipo' => 'haber', 'monto' => $bruto], ['codigo' => 'IMPNR', 'nombre' => 'No remunerativo (importado)', 'tipo' => 'no_remunerativo', 'monto' => $noRem], ['codigo' => 'IMPD', 'nombre' => 'Aportes y deducciones (importado)', 'tipo' => 'deduccion', 'monto' => $ded], ['codigo' => 'IMPC', 'nombre' => 'Contribuciones patronales (importado)', 'tipo' => 'contribucion', 'monto' => $contrib]]]);
                $n++;
            }
            if (! $n) throw ValidationException::withMessages(['csv' => 'No se importó ningún recibo. ' . implode(' · ', array_slice($errores, 0, 3))]);
            $liq->recalcular();
            $liq->update(['notas' => trim("Importado {$n} recibos." . ($errores ? ' Con avisos: ' . implode(' · ', array_slice($errores, 0, 5)) : ''))]);
            AuditLog::registrar('crear', $liq, "Importó liquidación {$liq->periodoLabel()} ({$n} recibos)");
            return $liq->fresh('items.empleado');
        });
    }

    // Confirmar: queda firme y se contabiliza (sueldos y cargas al gasto; neto, aportes y contribuciones al pasivo).
    public function confirmar(Liquidacion $liq): Liquidacion
    {
        abort_if($liq->estado !== 'borrador', 422, 'La liquidación ya está confirmada.');
        abort_if(! $liq->items()->count(), 422, 'La liquidación no tiene recibos.');
        return DB::transaction(function () use ($liq) {
            $liq->update(['estado' => 'confirmada']);
            $anticipos = (float) $liq->items()->sum('anticipos');
            $lineas = [
                ['clave' => 'sueldos', 'debe' => (float) $liq->total_bruto + (float) $liq->total_no_rem, 'haber' => 0, 'detalle' => 'Sueldos ' . $liq->periodoLabel()],
                ['clave' => 'cargas_sociales', 'debe' => (float) $liq->total_contribuciones, 'haber' => 0, 'detalle' => 'Contribuciones patronales'],
                ['clave' => 'sueldos_pagar', 'debe' => 0, 'haber' => (float) $liq->total_neto, 'detalle' => 'Neto a pagar'],
                ['clave' => 'cargas_pagar', 'debe' => 0, 'haber' => (float) $liq->total_deducciones - $anticipos + (float) $liq->total_contribuciones, 'detalle' => 'Aportes retenidos y contribuciones'],
            ];
            if ($anticipos > 0) $lineas[] = ['clave' => 'anticipos_personal', 'debe' => 0, 'haber' => $anticipos, 'detalle' => 'Anticipos descontados'];
            $a = $this->conta->asientoPorClaves($liq->business_id, null, $liq->fecha, 'Liquidación de sueldos ' . $liq->periodoLabel(), 'sueldos', $liq->id, $lineas);
            $liq->update(['asiento_id' => $a?->id]);
            AuditLog::registrar('confirmar', $liq, "Confirmó liquidación {$liq->periodoLabel()} · neto $ " . number_format((float) $liq->total_neto, 2, ',', '.'));
            return $liq->fresh();
        });
    }

    public function reabrir(Liquidacion $liq): void
    {
        abort_if($liq->estado !== 'confirmada', 422, 'Solo se reabre una liquidación confirmada y no pagada.');
        DB::transaction(function () use ($liq) {
            $this->conta->anular('sueldos', $liq->id, 'Liquidación reabierta');
            $liq->update(['estado' => 'borrador', 'asiento_id' => null]);
            AuditLog::registrar('editar', $liq, "Reabrió liquidación {$liq->periodoLabel()}");
        });
    }

    // Paga los netos desde una cuenta de fondos (baja Sueldos a pagar). Opcionalmente también las cargas.
    public function pagar(Liquidacion $liq, CuentaFondos $cuenta, string $fecha, bool $cargas = false): void
    {
        abort_if($liq->estado !== 'confirmada', 422, 'Confirmá la liquidación antes de pagarla.');
        DB::transaction(function () use ($liq, $cuenta, $fecha, $cargas) {
            $this->fondos->registrar($cuenta, ['fecha' => $fecha, 'origen' => 'sueldos', 'origen_id' => $liq->id, 'concepto' => 'Pago de sueldos ' . $liq->periodoLabel(), 'egreso' => (float) $liq->total_neto]);
            if ($cargas) $this->pagarCargas($liq, $cuenta, $fecha);
            $liq->update(['estado' => 'pagada', 'pagada_en' => now()]);
            AuditLog::registrar('pagar', $liq, "Pagó sueldos {$liq->periodoLabel()} desde {$cuenta->nombre}");
        });
    }

    public function pagarCargas(Liquidacion $liq, CuentaFondos $cuenta, string $fecha): void
    {
        $monto = round((float) $liq->total_deducciones - (float) $liq->items()->sum('anticipos') + (float) $liq->total_contribuciones, 2);
        if ($monto <= 0) return;
        $this->fondos->registrar($cuenta, ['fecha' => $fecha, 'origen' => 'cargas_sociales', 'origen_id' => $liq->id, 'concepto' => 'Cargas sociales (F931) ' . $liq->periodoLabel(), 'egreso' => $monto]);
        AuditLog::registrar('pagar', $liq, "Pagó cargas sociales {$liq->periodoLabel()} $ " . number_format($monto, 2, ',', '.'));
    }

    // Anticipo a un empleado: sale de caja y queda como crédito hasta la próxima liquidación.
    public function anticipo(Empleado $e, CuentaFondos $cuenta, float $monto, string $fecha): void
    {
        $this->fondos->registrar($cuenta, ['fecha' => $fecha, 'origen' => 'anticipo_sueldo', 'origen_id' => $e->id, 'concepto' => "Anticipo de sueldo · {$e->nombre}", 'egreso' => $monto]);
        AuditLog::registrar('pagar', $e, "Anticipo $ " . number_format($monto, 2, ',', '.') . " a {$e->nombre}");
    }

    // Anticipos pagados y todavía no descontados en una liquidación confirmada.
    public function anticiposPendientes(Empleado $e): float
    {
        $pagados = (float) \App\Models\MovimientoFondos::where('origen', 'anticipo_sueldo')->where('origen_id', $e->id)->sum('egreso');
        $descontados = (float) LiquidacionItem::where('empleado_id', $e->id)->whereHas('liquidacion', fn($q) => $q->where('estado', '!=', 'borrador'))->sum('anticipos');
        return max(0, round($pagados - $descontados, 2));
    }

    // Libro de sueldos (CSV) para el contador o el aplicativo.
    public function libroCsv(Liquidacion $liq): string
    {
        $out = "Legajo;CUIL;Empleado;Categoría;Días;Bruto;No remunerativo;Deducciones;Neto;Contribuciones\n";
        foreach ($liq->items()->with('empleado')->get() as $i) $out .= implode(';', [$i->empleado->legajo, $i->empleado->cuil, $i->empleado->nombre, $i->empleado->categoria, $i->dias, ...array_map(fn($v) => number_format((float) $v, 2, ',', ''), [$i->bruto, $i->no_rem, $i->deducciones, $i->neto, $i->contribuciones])]) . "\n";
        return $out;
    }
}
