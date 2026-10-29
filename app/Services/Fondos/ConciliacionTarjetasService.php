<?php

namespace App\Services\Fondos;

use App\Models\AuditLog;
use App\Models\Conciliacion;
use App\Models\CuentaFondos;
use App\Models\CuponTarjeta;
use App\Models\LiquidacionTarjeta;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Fase 35.2 y 35.4: cruza la liquidación de la procesadora contra los cupones de BigSysWeb y arma el resultado en cuatro grupos
// (listo para tildar, todavía no te pagaron, comisiones e impuestos, a revisar); después, la previa contable, la prueba sin escribir,
// el asiento en una sola operación (una liquidación por acreditación) y el deshacer.
class ConciliacionTarjetasService
{
    public function __construct(private LiquidacionLectorService $lector, private TarjetasService $tarjetas) {}

    // 35.1: sugerencia de qué es cada fondo según cómo se usa, y lo guardado.
    public function fondos(): array
    {
        return CuentaFondos::where('activa', true)->orderBy('tipo')->orderBy('nombre')->get()->map(function ($c) {
            $cfg = $c->conciliacion_config ?? []; $n = mb_strtolower($c->nombre . ' ' . $c->banco);
            $sug = match (true) { str_contains($n, 'naranja') => 'naranjax', str_contains($n, 'mercado') || str_contains($n, 'mp') => 'mercadopago', str_contains($n, 'posnet') || str_contains($n, 'fiserv') => 'fiserv', str_contains($n, 'nave') => 'nave', str_contains($n, 'gocuotas') => 'gocuotas', default => null };
            return ['id' => $c->id, 'nombre' => $c->nombre, 'tipo' => $c->tipo, 'concilia' => (bool) $c->concilia && $c->tipo !== 'caja', 'procesadoras' => $cfg['procesadoras'] ?? ($sug ? [$sug] : []), 'sugerida' => $sug, 'comision_pactada' => $cfg['comision_pactada'] ?? null, 'plazo_pactado' => $cfg['plazo_pactado'] ?? null, 'banco_id' => $cfg['banco_id'] ?? null, 'terminales' => $cfg['terminales'] ?? [], 'cupones_cartera' => $c->tipo === 'tarjeta' || $c->tipo === 'billetera' ? $this->cuponesDe($c)->where('estado', 'cartera')->count() : null];
        })->values()->all();
    }

    public function guardarFondos(array $fondos): void
    {
        foreach ($fondos as $f) {
            $c = CuentaFondos::findOrFail((int) $f['id']);
            $c->update(['concilia' => (bool) ($f['concilia'] ?? true), 'conciliacion_config' => array_filter(['procesadoras' => array_values(array_intersect((array) ($f['procesadoras'] ?? []), array_keys(Conciliacion::ORIGENES))), 'comision_pactada' => isset($f['comision_pactada']) && $f['comision_pactada'] !== '' ? (float) $f['comision_pactada'] : null, 'plazo_pactado' => isset($f['plazo_pactado']) && $f['plazo_pactado'] !== '' ? (int) $f['plazo_pactado'] : null, 'banco_id' => ! empty($f['banco_id']) ? (int) $f['banco_id'] : null, 'terminales' => $c->conciliacion_config['terminales'] ?? []], fn($v) => $v !== null)]);
        }
        AuditLog::registrar('editar', null, 'Qué es cada fondo: ' . count($fondos) . ' fondos configurados para conciliar');
    }

    // Los cupones que caen en un fondo: los que la caja anotó a ese fondo, más los que no dicen fondo (quedan para el único fondo de tarjeta, o para todos).
    private function cuponesDe(CuentaFondos $fondo)
    {
        $soloEste = CuentaFondos::where('activa', true)->whereIn('tipo', ['tarjeta', 'billetera'])->count() === 1;
        return CuponTarjeta::with('medio:id,cuenta_fondos_id')->whereIn('estado', ['cartera', 'liquidado'])->get()->filter(fn($c) => ($c->medio?->cuenta_fondos_id ?? null) === $fondo->id || ($c->medio?->cuenta_fondos_id === null && ($soloEste || $fondo->tipo === 'tarjeta')));
    }

    public function subir(CuentaFondos $fondo, string $path, string $nombre, ?string $origen = null): Conciliacion
    {
        abort_if($fondo->concilia === false, 422, "El fondo {$fondo->nombre} está marcado como «no se concilia». Cambialo en «Qué es cada fondo».");
        if ($origen && in_array($origen, Conciliacion::SIN_LECTOR, true)) throw ValidationException::withMessages(['archivo' => Conciliacion::ORIGENES[$origen] . ': todavía falta un archivo real para armar el lector. Mandanos una liquidación de verdad y se agrega.']);
        $l = $this->lector->leer($path, $nombre, $origen);
        $cfg = $fondo->conciliacion_config ?? [];
        // Aviso antes de duplicar: el mismo período ya cargado contra este fondo.
        $dup = Conciliacion::where('tipo', 'tarjeta')->where('cuenta_fondos_id', $fondo->id)->where('estado', '!=', 'deshecha')->where('desde', '<=', $l['periodo']['hasta'])->where('hasta', '>=', $l['periodo']['desde'])->latest('id')->first();
        $otroOrigen = Conciliacion::where('tipo', 'tarjeta')->where('cuenta_fondos_id', $fondo->id)->where('origen', '!=', $l['origen'])->where('estado', '!=', 'deshecha')->exists();
        $res = $this->cruzar($fondo, $l['operaciones'], $l['periodo'], $cfg);
        $avisos = array_values(array_filter([
            $dup ? "Ya cargaste una liquidación de {$fondo->nombre} que pisa este período (lote #{$dup->id}, " . $dup->created_at->format('d/m') . '). Los cupones ya tildados se muestran aparte y no se cuentan dos veces.' : null,
            $otroOrigen ? "Este fondo también recibe de otras procesadoras: el «todavía no te pagaron» incluye cupones que pueden venir en otra liquidación." : null,
            $l['control']['cierra'] === false ? 'El total del archivo (' . number_format($l['control']['total_archivo'], 2, ',', '.') . ') no coincide con lo leído (' . number_format($l['control']['total_leido'], 2, ',', '.') . '). Revisá si faltan filas.' : null,
            $l['faltan'] ? 'El archivo no trae: ' . implode(', ', array_map(fn($c) => ['cupon' => 'número de cupón', 'fecha_pago' => 'fecha de pago', 'neto' => 'neto', 'comision' => 'comisión'][$c] ?? $c, $l['faltan'])) . '. Se cruza por fecha e importe' . (in_array('neto', $l['faltan']) ? ' y la previa contable no va a poder armarse sin el neto' : '') . '.' : null,
            $l['formato']['faltan'] || $l['formato']['nuevas'] ? 'La procesadora cambió el formato: ' . ($l['formato']['faltan'] ? 'ya no viene ' . implode(', ', $l['formato']['faltan']) : '') . ($l['formato']['nuevas'] ? ' · columnas nuevas: ' . implode(', ', $l['formato']['nuevas']) : '') : null,
            in_array($l['origen'], Conciliacion::SIN_LECTOR, true) ? Conciliacion::ORIGENES[$l['origen']] . ': lector genérico, revisá el resultado con más cuidado.' : null,
        ]));
        $c = Conciliacion::create(['business_id' => $fondo->business_id, 'user_id' => Auth::id(), 'tipo' => 'tarjeta', 'origen' => $l['origen'], 'cuenta_fondos_id' => $fondo->id, 'banco_id' => $cfg['banco_id'] ?? null, 'archivo' => $nombre, 'desde' => $l['periodo']['desde'], 'hasta' => $l['periodo']['hasta'], 'resumen' => $res['resumen'], 'resultado' => $res + ['avisos' => $avisos, 'terminales' => $l['terminales'], 'encabezado' => $l['encabezado']], 'control' => $l['control']]);
        AuditLog::registrar('crear', $c, "Conciliación de " . (Conciliacion::ORIGENES[$l['origen']] ?? $l['origen']) . " en {$fondo->nombre}: {$res['resumen']['listo_n']} cerraron, {$res['resumen']['revisar_n']} a revisar");
        return $c;
    }

    // El cruce: mismo importe, fecha de venta a ±3 días; si hay cupón, los últimos 4 dígitos deciden entre varios. Nunca asigna a la fuerza.
    public function cruzar(CuentaFondos $fondo, array $ops, array $periodo, array $cfg = []): array
    {
        $pool = $this->cuponesDe($fondo)->sortBy('fecha')->values();
        $usados = []; $listo = []; $yaTildado = []; $revisar = [];
        $pactComision = isset($cfg['comision_pactada']) ? (float) $cfg['comision_pactada'] : null; $pactPlazo = isset($cfg['plazo_pactado']) ? (int) $cfg['plazo_pactado'] : null;
        $deMas = 0; $deMasMonto = 0.0; $tarde = 0;
        foreach ($ops as $o) {
            $cands = $pool->filter(fn($c) => ! isset($usados[$c->id]) && abs((float) $c->monto - $o['importe']) < 0.015 && abs($c->fecha->diffInDays(\Carbon\Carbon::parse($o['fecha_venta']), false)) <= 3)->values();
            $elegido = null; $como = null;
            if ($cands->count() === 1) { $elegido = $cands->first(); $como = 'importe y fecha'; }
            elseif ($cands->count() > 1 && $o['cupon']) {
                $suf = substr(preg_replace('/\D/', '', $o['cupon']), -4);
                $porCupon = $suf !== '' ? $cands->filter(fn($c) => $c->numero && str_ends_with(preg_replace('/\D/', '', $c->numero), $suf))->values() : collect();
                if ($porCupon->count() === 1) { $elegido = $porCupon->first(); $como = 'importe, fecha y cupón'; }
            }
            $fila = ['fila' => $o['fila'], 'fecha_venta' => $o['fecha_venta'], 'importe' => $o['importe'], 'cupon' => $o['cupon'], 'cuotas' => $o['cuotas'], 'fecha_pago' => $o['fecha_pago'], 'neto' => $o['neto'], 'comision' => $o['comision'], 'iva' => $o['iva'], 'retenciones' => round($o['ret_iva'] + $o['ret_iibb'] + $o['ret_ganancias'], 2), 'ret_iva' => $o['ret_iva'], 'ret_iibb' => $o['ret_iibb'], 'ret_ganancias' => $o['ret_ganancias'], 'otros' => $o['otros'], 'terminal' => $o['terminal'], 'acreditacion' => $o['acreditacion'] ?: ($o['fecha_pago'] ?: 'sin fecha de pago'), 'tarjeta' => $o['tarjeta']];
            // Lo pactado contra lo real
            if ($pactComision !== null && $o['importe'] > 0 && $o['comision'] > 0) { $pct = round($o['comision'] / $o['importe'] * 100, 2); $fila['comision_pct'] = $pct; if ($pct > $pactComision + 0.1) { $deMas++; $deMasMonto += $o['comision'] - $o['importe'] * $pactComision / 100; $fila['comision_de_mas'] = round($o['comision'] - $o['importe'] * $pactComision / 100, 2); } }
            if ($pactPlazo !== null && $o['fecha_pago']) { $dias = \Carbon\Carbon::parse($o['fecha_venta'])->diffInDays(\Carbon\Carbon::parse($o['fecha_pago']), false); $fila['dias_pago'] = $dias; if ($dias > $pactPlazo) { $tarde++; $fila['tarde'] = true; } }
            if ($elegido) {
                $usados[$elegido->id] = true;
                $fila += ['cupon_id' => $elegido->id, 'cupon_numero' => $elegido->numero, 'cupon_fecha' => $elegido->fecha->toDateString(), 'cliente' => $elegido->contact?->name, 'recibo' => $elegido->cobro?->numeroFormateado(), 'como' => $como];
                if ($elegido->estado === 'liquidado') { $fila['liquidacion_id'] = $elegido->liquidacion_tarjeta_id; $yaTildado[] = $fila; } else $listo[] = $fila;
            } else {
                $fila += ['motivo' => $cands->count() > 1 ? 'Hay ' . $cands->count() . ' cupones iguales en esas fechas' : 'No hay ningún cupón con ese importe en esas fechas', 'candidatos' => $cands->map(fn($c) => ['id' => $c->id, 'numero' => $c->numero, 'fecha' => $c->fecha->format('d/m'), 'monto' => (float) $c->monto, 'cliente' => $c->contact?->name, 'estado' => $c->estado])->values()->all(), 'tipo' => $cands->count() > 1 ? 'ambiguo' : 'sin_cupon'];
                $revisar[] = $fila;
            }
        }
        // Todavía no te pagaron: cupones en cartera del período que no están en el archivo.
        $noPagaron = $pool->filter(fn($c) => $c->estado === 'cartera' && ! isset($usados[$c->id]) && $c->fecha->toDateString() >= $periodo['desde'] && $c->fecha->toDateString() <= $periodo['hasta'])
            ->map(fn($c) => ['id' => $c->id, 'fecha' => $c->fecha->toDateString(), 'numero' => $c->numero, 'tarjeta' => $c->tarjeta, 'monto' => (float) $c->monto, 'cliente' => $c->contact?->name, 'dias' => $c->fecha->diffInDays(today())])->values()->all();
        // Comisiones e impuestos: por acreditación, el movimiento que falta con todas las letras.
        $acreditaciones = collect($listo)->groupBy('acreditacion')->map(function ($g, $k) {
            $bruto = round($g->sum('importe'), 2); $neto = $g->contains(fn($f) => $f['neto'] === null) ? null : round($g->sum('neto'), 2);
            $com = round($g->sum('comision'), 2); $iva = round($g->sum('iva'), 2); $ret = round($g->sum('retenciones'), 2); $otros = round($g->sum('otros'), 2);
            if ($neto !== null && abs($bruto - $com - $iva - $ret - $otros - $neto) > 0.05) $otros = round($bruto - $com - $iva - $ret - $neto, 2); // lo que el archivo descuenta sin nombrar
            return ['clave' => (string) $k, 'fecha_pago' => $g->first()['fecha_pago'], 'cupones' => $g->count(), 'cupon_ids' => $g->pluck('cupon_id')->all(), 'bruto' => $bruto, 'neto' => $neto, 'comision' => $com, 'iva' => $iva, 'ret_iva' => round($g->sum('ret_iva'), 2), 'ret_iibb' => round($g->sum('ret_iibb'), 2), 'ret_ganancias' => round($g->sum('ret_ganancias'), 2), 'otros' => $otros,
                'texto' => $neto === null ? "Salen $ " . number_format($bruto, 2, ',', '.') . " del fondo tarjeta; el archivo no dice el neto que entra al banco." : "Salen $ " . number_format($bruto, 2, ',', '.') . " del fondo tarjeta, entran $ " . number_format($neto, 2, ',', '.') . " al banco, y $ " . number_format($bruto - $neto, 2, ',', '.') . " son comisión, IVA y retenciones."];
        })->values()->all();
        $costo = round(array_sum(array_map(fn($a) => $a['bruto'] - ($a['neto'] ?? $a['bruto']), $acreditaciones)), 2);
        return ['listo' => $listo, 'ya_tildado' => $yaTildado, 'no_pagaron' => $noPagaron, 'acreditaciones' => $acreditaciones, 'revisar' => $revisar,
            'pactado' => ['comision' => $pactComision, 'plazo' => $pactPlazo, 'de_mas' => $deMas, 'de_mas_monto' => round($deMasMonto, 2), 'tarde' => $tarde],
            'resumen' => ['cerro' => round(array_sum(array_column($listo, 'importe')), 2), 'listo_n' => count($listo), 'ya_tildado_n' => count($yaTildado), 'no_pagaron' => round(array_sum(array_column($noPagaron, 'monto')), 2), 'no_pagaron_n' => count($noPagaron), 'comisiones' => $costo, 'revisar_n' => count($revisar), 'operaciones' => count($ops)]];
    }

    // A revisar → resuelto con un clic: se elige el cupón (o se deja sin cupón). Se puede deshacer volviéndolo a "revisar".
    public function resolver(Conciliacion $c, int $fila, ?int $cuponId): Conciliacion
    {
        abort_if($c->estado !== 'propuesta', 422, 'El lote ya está asentado.');
        $r = $c->resultado; $i = collect($r['revisar'])->search(fn($f) => $f['fila'] === $fila);
        if ($i === false) { // volver atrás: de listo a revisar
            $j = collect($r['listo'])->search(fn($f) => $f['fila'] === $fila); abort_if($j === false, 404);
            $f = $r['listo'][$j]; unset($r['listo'][$j]); $r['listo'] = array_values($r['listo']);
            $f = collect($f)->except(['cupon_id', 'cupon_numero', 'cupon_fecha', 'cliente', 'recibo', 'como'])->all() + ['motivo' => 'Lo volviste a revisar', 'candidatos' => [], 'tipo' => 'ambiguo'];
            $r['revisar'][] = $f;
        } else {
            $f = $r['revisar'][$i]; unset($r['revisar'][$i]); $r['revisar'] = array_values($r['revisar']);
            if ($cuponId) {
                $cup = CuponTarjeta::findOrFail($cuponId); abort_if(collect($r['listo'])->contains('cupon_id', $cup->id), 422, 'Ese cupón ya está usado en este lote.');
                $f = collect($f)->except(['motivo', 'candidatos', 'tipo'])->all() + ['cupon_id' => $cup->id, 'cupon_numero' => $cup->numero, 'cupon_fecha' => $cup->fecha->toDateString(), 'cliente' => $cup->contact?->name, 'recibo' => $cup->cobro?->numeroFormateado(), 'como' => 'a mano'];
                $r['listo'][] = $f; $r['no_pagaron'] = array_values(array_filter($r['no_pagaron'], fn($n) => $n['id'] !== $cup->id));
            } else { $f['tipo'] = 'descartado'; $f['motivo'] = 'Descartado a mano (no es una venta de este sistema)'; $r['descartados'][] = $f; }
        }
        $fondo = $c->cuenta; $re = $this->rearmar($r);
        $c->update(['resultado' => $re, 'resumen' => $re['resumen'], 'probado_en' => null]);
        return $c->fresh();
    }

    private function rearmar(array $r): array
    {
        $listo = collect($r['listo'])->sortBy('fila')->values()->all();
        $acred = collect($listo)->groupBy('acreditacion')->map(function ($g, $k) {
            $bruto = round($g->sum('importe'), 2); $neto = $g->contains(fn($f) => $f['neto'] === null) ? null : round($g->sum('neto'), 2);
            $com = round($g->sum('comision'), 2); $iva = round($g->sum('iva'), 2); $ret = round($g->sum('retenciones'), 2); $otros = round($g->sum('otros'), 2);
            if ($neto !== null && abs($bruto - $com - $iva - $ret - $otros - $neto) > 0.05) $otros = round($bruto - $com - $iva - $ret - $neto, 2);
            return ['clave' => (string) $k, 'fecha_pago' => $g->first()['fecha_pago'], 'cupones' => $g->count(), 'cupon_ids' => $g->pluck('cupon_id')->all(), 'bruto' => $bruto, 'neto' => $neto, 'comision' => $com, 'iva' => $iva, 'ret_iva' => round($g->sum('ret_iva'), 2), 'ret_iibb' => round($g->sum('ret_iibb'), 2), 'ret_ganancias' => round($g->sum('ret_ganancias'), 2), 'otros' => $otros, 'texto' => $neto === null ? "Salen $ " . number_format($bruto, 2, ',', '.') . " del fondo tarjeta; el archivo no dice el neto." : "Salen $ " . number_format($bruto, 2, ',', '.') . " del fondo tarjeta, entran $ " . number_format($neto, 2, ',', '.') . " al banco, y $ " . number_format($bruto - $neto, 2, ',', '.') . " son comisión, IVA y retenciones."];
        })->values()->all();
        $r['listo'] = $listo; $r['acreditaciones'] = $acred;
        $r['resumen'] = array_merge($r['resumen'], ['cerro' => round(array_sum(array_column($listo, 'importe')), 2), 'listo_n' => count($listo), 'no_pagaron' => round(array_sum(array_column($r['no_pagaron'], 'monto')), 2), 'no_pagaron_n' => count($r['no_pagaron']), 'comisiones' => round(array_sum(array_map(fn($a) => $a['bruto'] - ($a['neto'] ?? $a['bruto']), $acred)), 2), 'revisar_n' => count($r['revisar'])]);
        return $r;
    }

    // 35.4 Previa en formato contable: por acreditación, cuenta / debe / haber, y abajo debe = haber. Si no cierra o falta algo, lo dice y no se habilita.
    public function previa(Conciliacion $c): array
    {
        $r = $c->resultado; $banco = $c->banco_id ? CuentaFondos::find($c->banco_id) : null; $faltan = [];
        if (! $banco) $faltan[] = 'el banco donde se deposita el neto (elegilo arriba o en «Qué es cada fondo»)';
        $acreds = []; $td = 0; $th = 0; $puede = true;
        foreach ($r['acreditaciones'] as $a) {
            $ids = array_filter($a['cupon_ids']); $enCartera = CuponTarjeta::whereIn('id', $ids)->where('estado', 'cartera')->count();
            $lineas = []; $problemas = [];
            if ($a['neto'] === null) $problemas[] = 'el archivo no trae el neto';
            if ($enCartera !== count($ids)) $problemas[] = (count($ids) - $enCartera) . ' cupones ya no están en cartera';
            if ($a['neto'] !== null && $a['neto'] <= 0) $problemas[] = 'el neto es cero o negativo';
            if ($a['neto'] !== null) {
                $lineas[] = ['cuenta' => 'Banco ' . ($banco?->nombre ?? '—'), 'debe' => $a['neto'], 'haber' => 0];
                if ($a['comision'] + $a['otros'] > 0) $lineas[] = ['cuenta' => 'Comisiones de tarjeta (gasto)', 'debe' => round($a['comision'] + $a['otros'], 2), 'haber' => 0];
                if ($a['iva'] > 0) $lineas[] = ['cuenta' => 'IVA crédito fiscal', 'debe' => $a['iva'], 'haber' => 0];
                if ($a['ret_iva'] + $a['ret_iibb'] + $a['ret_ganancias'] > 0) $lineas[] = ['cuenta' => 'Retenciones sufridas (IVA ' . number_format($a['ret_iva'], 2, ',', '.') . ' · IIBB ' . number_format($a['ret_iibb'], 2, ',', '.') . ' · Gan. ' . number_format($a['ret_ganancias'], 2, ',', '.') . ')', 'debe' => round($a['ret_iva'] + $a['ret_iibb'] + $a['ret_ganancias'], 2), 'haber' => 0];
                $lineas[] = ['cuenta' => 'Cupones a cobrar (' . $a['cupones'] . ' cupones)', 'debe' => 0, 'haber' => $a['bruto']];
            }
            $d = round(array_sum(array_column($lineas, 'debe')), 2); $h = round(array_sum(array_column($lineas, 'haber')), 2);
            if ($lineas && abs($d - $h) > 0.009) $problemas[] = 'debe ≠ haber por $ ' . number_format($d - $h, 2, ',', '.');
            if ($problemas) $puede = false;
            $td += $d; $th += $h;
            $acreds[] = ['clave' => $a['clave'], 'fecha_pago' => $a['fecha_pago'], 'texto' => $a['texto'], 'lineas' => $lineas, 'debe' => $d, 'haber' => $h, 'problemas' => $problemas, 'cupones' => $a['cupones']];
        }
        if (! $r['acreditaciones']) { $puede = false; $faltan[] = 'ninguna operación cerró: no hay nada para asentar'; }
        if ($faltan) $puede = false;
        return ['acreditaciones' => $acreds, 'debe' => round($td, 2), 'haber' => round($th, 2), 'puede' => $puede, 'faltan' => $faltan, 'banco' => $banco?->nombre, 'probado' => (bool) $c->probado_en, 'texto' => count($acreds) . (count($acreds) === 1 ? ' liquidación' : ' liquidaciones') . ' de tarjeta en ' . ($c->cuenta?->nombre ?? 'el fondo') . ', ' . collect($r['listo'])->count() . ' cupones pasan de cartera a liquidados, y el neto entra en ' . ($banco?->nombre ?? '—') . '.'];
    }

    // Probar sin escribir: corre las mismas validaciones del asiento dentro de una transacción que se deshace. Obligatorio antes de asentar.
    public function probar(Conciliacion $c): array
    {
        $p = $this->previa($c); if (! $p['puede']) return $p + ['ok' => false];
        try {
            DB::transaction(function () use ($c) { $this->escribir($c); throw new \RuntimeException('__simulacion__'); });
        } catch (\RuntimeException $e) { if ($e->getMessage() !== '__simulacion__') return $p + ['ok' => false, 'faltan' => [$e->getMessage()]]; }
        catch (\Throwable $e) { return $p + ['ok' => false, 'puede' => false, 'faltan' => ['La prueba falló: ' . $e->getMessage()]]; }
        $c->update(['probado_en' => now()]);
        return $p + ['ok' => true, 'probado' => true];
    }

    public function asentar(Conciliacion $c): Conciliacion
    {
        abort_if($c->estado !== 'propuesta', 422, 'Este lote ya se asentó.');
        abort_if(! $c->probado_en, 422, 'Primero tocá «Ver qué escribiría»: la prueba es obligatoria antes de asentar.');
        $p = $this->previa($c); if (! $p['puede']) throw ValidationException::withMessages(['asentar' => 'No se puede asentar: ' . implode('; ', array_merge($p['faltan'], collect($p['acreditaciones'])->pluck('problemas')->flatten()->all()))]);
        return DB::transaction(function () use ($c) {
            $ids = $this->escribir($c);
            $c->update(['estado' => 'asentada', 'asentado' => ['liquidaciones' => $ids, 'usuario' => Auth::user()?->name, 'cuando' => now()->format('d/m/Y H:i')], 'asentado_en' => now()]);
            AuditLog::registrar('crear', $c, 'Asentó la conciliación #' . $c->id . ': ' . count($ids) . ' liquidaciones de tarjeta');
            return $c->fresh();
        });
    }

    private function escribir(Conciliacion $c): array
    {
        $ids = [];
        foreach ($c->resultado['acreditaciones'] as $a) {
            $liq = $this->tarjetas->liquidar(['cupones' => array_filter($a['cupon_ids']), 'cuenta_fondos_id' => $c->banco_id, 'fecha' => $a['fecha_pago'] ?: today()->toDateString(), 'numero' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $a['clave']) ? null : $a['clave'], 'tarjeta' => Conciliacion::ORIGENES[$c->origen] ?? $c->origen, 'comision' => $a['comision'], 'iva_comision' => $a['iva'], 'ret_iva' => $a['ret_iva'], 'ret_iibb' => $a['ret_iibb'], 'ret_ganancias' => $a['ret_ganancias'], 'otros' => $a['otros'], 'notas' => "Conciliación #{$c->id} · {$c->archivo}"]);
            $ids[] = $liq->id;
        }
        return $ids;
    }

    public function deshacer(Conciliacion $c): Conciliacion
    {
        abort_if($c->estado !== 'asentada', 422, 'Este lote no está asentado.');
        return DB::transaction(function () use ($c) {
            foreach ($c->asentado['liquidaciones'] ?? [] as $id) if (($l = LiquidacionTarjeta::find($id)) && $l->estado !== 'anulada') $this->tarjetas->anular($l, "Deshecha la conciliación #{$c->id}");
            $c->update(['estado' => 'deshecha', 'probado_en' => null]);
            AuditLog::registrar('anular', $c, "Deshizo la conciliación #{$c->id}: los cupones vuelven a cartera");
            return $c->fresh();
        });
    }
}
