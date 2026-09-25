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

    public const DETRACCION = 7003.68; // Ley 27.541: se descuenta de la base de contribuciones, proporcional a la jornada

    // Redondeo a 2 decimales, mitad hacia arriba, a prueba de errores de coma flotante (54611,295 → 54611,30).
    private static function r2(float $x): float { return round($x + ($x >= 0 ? 1e-7 : -1e-7), 2); }

    // Calcula un recibo. $nov: dias, feriados, vacaciones, horas_extra_50, horas_extra_100, adicionales, no_rem_extra, anticipos.
    // Los conceptos se calculan por tipo (haberes, no remunerativos, deducciones, contribuciones) y dentro de cada tipo por orden,
    // así REM y NOREM ya están completos cuando se calculan los aportes.
    public function calcular(Empleado $e, $conceptos, array $nov, string $tipo = 'mensual', ?Carbon $periodo = null, array $cfg = []): array
    {
        $periodo ??= today();
        $basico = (float) $e->sueldo_basico;
        $jornada = (float) ($e->jornada ?: 1);
        $anios = $e->antiguedadAnios($periodo);
        $dias = (int) ($nov['dias'] ?? 30); $feriados = (int) ($nov['feriados'] ?? 0); $vacaciones = (int) ($nov['vacaciones'] ?? 0);
        $cant = ['dias' => $dias, 'feriados' => $feriados, 'vacaciones' => $vacaciones, 'anios' => $anios, 'horas_extra_50' => (float) ($nov['horas_extra_50'] ?? 0), 'horas_extra_100' => (float) ($nov['horas_extra_100'] ?? 0)];
        $asignados = $e->relationLoaded('conceptos') ? $e->conceptos : $e->conceptos()->get();
        $det = []; $bruto = 0.0; $noRem = 0.0; $ded = 0.0; $contrib = 0.0;
        $agregar = function (string $codigo, string $nombre, string $t, float $monto, $cantidad = null, ?string $unidad = null, ?string $grupo = null) use (&$det, &$bruto, &$noRem, &$ded, &$contrib) {
            $monto = self::r2($monto); if (abs($monto) < 0.005) return;
            $det[] = ['codigo' => $codigo, 'nombre' => $nombre, 'tipo' => $t, 'monto' => $monto, 'cantidad' => $cantidad, 'unidad' => $unidad, 'grupo' => $grupo];
            match ($t) { 'haber' => $bruto += $monto, 'no_remunerativo' => $noRem += $monto, 'deduccion' => $ded += $monto, 'contribucion' => $contrib += $monto, default => null };
        };
        // Sin un concepto de "días" (conceptos genéricos) el básico se liquida como antes: proporcional a los días.
        $conDias = $tipo !== 'sac' && $conceptos->contains(fn($c) => $c->tipo === 'haber' && $c->cantidad === 'dias');
        $basicoHaber = $basico;
        if ($tipo === 'sac') {
            // Aguinaldo: 50 % de la mejor remuneración mensual del semestre, proporcional a los días trabajados.
            $desde = $periodo->month <= 6 ? $periodo->copy()->startOfYear() : $periodo->copy()->month(7)->startOfMonth();
            $mejor = (float) LiquidacionItem::where('empleado_id', $e->id)->whereHas('liquidacion', fn($q) => $q->where('tipo', 'mensual')->whereBetween('periodo', [$desde->format('Y-m'), $periodo->format('Y-m')]))->max('bruto');
            if ($mejor <= 0) { $ant = $conceptos->first(fn($c) => $c->por_anio && $c->tipo === 'haber'); $mejor = $basico * (1 + ($ant ? (float) $ant->valor * $anios / 100 : 0)); }
            $agregar('SAC', 'Sueldo anual complementario', 'haber', $mejor / 2 * min(1, $dias / 180), $dias);
        } elseif (! $conDias) {
            $prop = $e->modalidad === 'jornal' ? $dias : min(1, $dias / 30);
            $basicoHaber = self::r2($basico * $prop);
            $agregar('BAS', $e->modalidad === 'jornal' ? "Jornales ({$dias} días)" : 'Sueldo básico' . ($dias < 30 ? " ({$dias} días)" : ''), 'haber', $basicoHaber, $dias);
        }
        if ($tipo !== 'sac') {
            $valorHora = $basico / 200;
            if ($cant['horas_extra_50'] > 0) $agregar('HE50', "Horas extra 50% ({$cant['horas_extra_50']} h)", 'haber', $cant['horas_extra_50'] * $valorHora * 1.5, $cant['horas_extra_50']);
            if ($cant['horas_extra_100'] > 0) $agregar('HE100', "Horas extra 100% ({$cant['horas_extra_100']} h)", 'haber', $cant['horas_extra_100'] * $valorHora * 2, $cant['horas_extra_100']);
            if (($a = (float) ($nov['adicionales'] ?? 0)) > 0) $agregar('ADI', 'Adicionales / premios', 'haber', $a);
        }
        $detraccion = (float) ($cfg['detraccion'] ?? self::DETRACCION);
        $orden = ['haber' => 0, 'no_remunerativo' => 1, 'deduccion' => 2, 'contribucion' => 3];
        foreach ($conceptos->sortBy(fn($c) => [$orden[$c->tipo] ?? 9, $c->orden, $c->id]) as $c) {
            // En el aguinaldo solo corren los aportes y contribuciones porcentuales.
            if ($tipo === 'sac' && (in_array($c->tipo, ['haber', 'no_remunerativo'], true) || $c->modo !== 'porcentaje')) continue;
            $asig = $asignados->firstWhere('id', $c->id);
            if ($c->solo_asignados && ! $asig) continue;
            if ($c->tipo === 'haber' && $c->cantidad === 'dias' && $tipo === 'sac') continue;
            $valor = $asig && $asig->pivot->valor !== null ? (float) $asig->pivot->valor : (float) $c->valor;
            $base = $this->base($c->base, $c->tipo === 'haber' ? $basicoHaber : $basico, $bruto, $noRem, $det);
            if ($c->jornada_completa && $jornada > 0) $base /= $jornada;
            if ($c->con_detraccion) $base = max(0, $base - $detraccion * $jornada);
            $cantidad = $c->cantidad ? ($cant[$c->cantidad] ?? 0) : 1;
            $monto = match ($c->modo) {
                'fijo' => $valor * ($c->proporcional_jornada ? $jornada : 1),
                'division' => $valor > 0 ? $base / $valor * $cantidad : 0,
                default => $base * $valor * ($c->por_anio ? $anios : 1) / 100,
            };
            if ((float) $c->mas_antiguedad > 0) $monto *= 1 + $anios * (float) $c->mas_antiguedad / 100;
            $agregar($c->codigo, $c->nombre, $c->tipo, $monto, $c->cantidad ? $cantidad : null, $c->etiqueta, $c->grupo);
        }
        $noRemExtra = (float) ($nov['no_rem_extra'] ?? 0);
        if ($noRemExtra > 0) $agregar('NR', 'No remunerativo', 'no_remunerativo', $noRemExtra);
        $ant = self::r2((float) ($nov['anticipos'] ?? 0));
        if ($ant > 0) $det[] = ['codigo' => 'ANTI', 'nombre' => 'Anticipos ya pagados', 'tipo' => 'deduccion', 'monto' => $ant, 'cantidad' => null, 'unidad' => null, 'grupo' => null];
        $neto = self::r2($bruto + $noRem - $ded - $ant);
        // Redondeo al peso siguiente (como el recibo del estudio): la diferencia va como "redondeo" y suma al neto.
        $redondeo = 0.0;
        if (($cfg['redondeo'] ?? 'no') === 'peso' && $neto > 0) {
            $redondeo = self::r2(ceil(round($neto, 2)) - $neto);
            if ($redondeo > 0) { $det[] = ['codigo' => $cfg['codigo_redondeo'] ?? 'RED', 'nombre' => 'REDONDEO', 'tipo' => 'redondeo', 'monto' => $redondeo, 'cantidad' => null, 'unidad' => null, 'grupo' => null]; $neto = self::r2($neto + $redondeo); }
        }
        return ['dias' => $dias, 'feriados' => $feriados, 'vacaciones' => $vacaciones, 'horas_extra_50' => $cant['horas_extra_50'], 'horas_extra_100' => $cant['horas_extra_100'], 'adicionales' => (float) ($nov['adicionales'] ?? 0), 'no_rem_extra' => $noRemExtra, 'anticipos' => $ant,
            'bruto' => self::r2($bruto), 'no_rem' => self::r2($noRem), 'deducciones' => self::r2($ded), 'neto' => $neto, 'contribuciones' => self::r2($contrib), 'redondeo' => $redondeo, 'detalle' => $det];
    }

    // Base de un concepto: BASICO, REM (remunerativo hasta acá), NOREM o códigos, sumados con "+".
    private function base(?string $expr, float $basico, float $rem, float $noRem, array $det): float
    {
        $total = 0.0;
        foreach (preg_split('/\s*\+\s*/', strtoupper(trim((string) ($expr ?: 'BASICO')))) as $tok) {
            $total += match ($tok) { 'BASICO', 'BASICO_PROP' => $basico, 'REM', 'BRUTO' => $rem, 'NOREM', 'NR' => $noRem, '' => 0, default => (float) collect($det)->where('codigo', $tok)->sum('monto') };
        }
        return $total;
    }

    // Configuración de sueldos de la empresa (detracción, redondeo, datos del recibo).
    public function config(Business $b): array
    {
        return array_replace(['dia_pago' => 4, 'cuenta_id' => null, 'detraccion' => self::DETRACCION, 'redondeo' => 'no', 'codigo_redondeo' => 'RED', 'actividad' => '', 'convenio' => '', 'obra_social' => '', 'lugar_pago' => '', 'deposito_banco' => ''], (array) ($b->sueldos ?? []));
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
            $cfg = $this->config($b);
            $per = Carbon::parse($periodo . '-01')->endOfMonth();
            foreach (Empleado::where('activo', true)->with('conceptos')->orderBy('legajo')->get() as $e) {
                $nov = $novedades[$e->id] ?? [];
                if (! empty($nov['excluir'])) continue;
                $liq->items()->create(['empleado_id' => $e->id] + $this->calcular($e, $conceptos, $nov, $tipo, $per, $cfg));
            }
            // Último depósito de aportes (art. 140 LCT): el que se cargó, o el mes anterior con el banco de la configuración.
            if (! $liq->deposito_periodo) $liq->forceFill(['deposito_periodo' => Carbon::parse($periodo . '-01')->subMonth()->format('Y-m'), 'deposito_banco' => $cfg['deposito_banco'] ?: null])->save();
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
    // Resumen para el F.931 / Libro de Sueldos Digital: por empleado, remuneración, no remunerativo, aportes retenidos y contribuciones, con totales.
    public function resumen931(Liquidacion $liq): string
    {
        $out = "Periodo;CUIL;Empleado;Dias;Remuneracion bruta;No remunerativo;Aportes retenidos;Contribuciones patronales;Neto;Obra social\n";
        $t = ['bruto' => 0, 'no_rem' => 0, 'ded' => 0, 'contrib' => 0, 'neto' => 0];
        foreach ($liq->items()->with('empleado')->get() as $i) {
            $aportes = collect($i->detalle ?? [])->where('tipo', 'deduccion')->where('codigo', '!=', 'ANTI')->sum('monto');
            foreach (['bruto' => $i->bruto, 'no_rem' => $i->no_rem, 'ded' => $aportes, 'contrib' => $i->contribuciones, 'neto' => $i->neto] as $k => $v) $t[$k] += (float) $v;
            $out .= implode(';', [$liq->periodo, preg_replace('/\D/', '', (string) $i->empleado->cuil), $i->empleado->nombre, $i->dias, ...array_map(fn($v) => number_format((float) $v, 2, ',', ''), [$i->bruto, $i->no_rem, $aportes, $i->contribuciones, $i->neto]), $i->empleado->obra_social ?? '']) . "\n";
        }
        $out .= implode(';', [$liq->periodo, '', 'TOTALES', $liq->items()->count(), ...array_map(fn($v) => number_format($v, 2, ',', ''), [$t['bruto'], $t['no_rem'], $t['ded'], $t['contrib'], $t['neto']]), '']) . "\n";
        return $out;
    }

    public function libroCsv(Liquidacion $liq): string
    {
        $out = "Legajo;CUIL;Empleado;Categoría;Días;Bruto;No remunerativo;Deducciones;Neto;Contribuciones\n";
        foreach ($liq->items()->with('empleado')->get() as $i) $out .= implode(';', [$i->empleado->legajo, $i->empleado->cuil, $i->empleado->nombre, $i->empleado->categoria, $i->dias, ...array_map(fn($v) => number_format((float) $v, 2, ',', ''), [$i->bruto, $i->no_rem, $i->deducciones, $i->neto, $i->contribuciones])]) . "\n";
        return $out;
    }
}
