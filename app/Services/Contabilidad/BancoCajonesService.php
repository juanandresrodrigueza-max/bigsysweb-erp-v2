<?php

namespace App\Services\Contabilidad;

use App\Models\CuentaFondos;
use App\Models\ExpenseCategory;
use App\Models\ExtractoBancario;
use App\Models\ExtractoItem;
use App\Models\MovimientoFondos;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Fase 35.3: el extracto repartido como lo hace el contador. 1) Está en los dos. 2) Está en el sistema y todavía no en el banco
// (el cheque que nadie cobró: normal, con los días). 3) Está en el banco y no en el sistema: la lista para cargar agrupada por concepto
// (impuestos, comisiones, IVA, intereses), las acreditaciones de tarjeta aparte, y lo que no es gasto a revisar. Abajo, el puente de saldos.
class BancoCajonesService
{
    public const CLASES = ['impuesto' => 'Impuestos (débitos y créditos, IIBB, SIRCREB)', 'comision' => 'Comisiones y gastos bancarios', 'iva' => 'IVA de gastos bancarios', 'interes' => 'Intereses', 'tarjeta' => 'Acreditaciones de tarjetas y billeteras', 'cheque' => 'Cheques', 'transferencia' => 'Transferencias y DEBIN', 'otro' => 'Otros a revisar'];
    public const SON_GASTO = ['impuesto', 'comision', 'iva', 'interes'];

    // Tabla de fábrica: palabras del concepto que usan Galicia, Credicoop, Nación y Santander. El orden importa (IVA antes que comisión).
    private const FABRICA = [
        'tarjeta' => ['fiserv', 'first data', 'firstdata', 'posnet', 'prisma', 'payway', 'naranja', 'nave', 'mercadopago', 'mercado pago', 'getnet', 'gocuotas', 'liq tarj', 'liquidacion tarjeta', 'visa', 'mastercard', 'cabal', 'amex'],
        'impuesto' => ['ley 25413', '25413', 'imp deb', 'imp cred', 'impuesto deb', 'impuesto cred', 'imp s/deb', 'imp s/cred', 'sircreb', 'iibb', 'ingresos brutos', 'percepcion', 'perc ', 'ret iibb', 'impuesto al cheque', 'imp. ley', 'impuesto ley'],
        'iva' => ['iva ', 'i.v.a', 'iva-', 'iva rg', 'iva tasa'],
        'interes' => ['interes', 'intereses', 'int. ', 'int deud', 'int acreed'],
        'comision' => ['comision', 'com ', 'com.', 'mantenimiento', 'mant cta', 'gasto', 'cargo', 'paquete', 'servicio cuenta', 'seguro', 'chequera', 'resumen'],
        'cheque' => ['cheque', 'ch ', 'cheq', 'echeq', 'clearing', 'canje'],
        'transferencia' => ['transf', 'transferencia', 'debin', 'trf', 'deposito', 'dep efectivo', 'credito inmediato', 'pago a proveedores', 'acreditacion haberes'],
    ];

    // La clave con la que se recuerda un concepto: el texto sin números ni fechas, las tres primeras palabras.
    public function clave(string $desc): string
    {
        $s = mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $desc) ?: $desc);
        $s = preg_replace(['/\d+/', '/[^a-z ]/'], ' ', $s);
        return implode(' ', array_slice(array_values(array_filter(explode(' ', $s), fn($w) => strlen($w) > 1)), 0, 3));
    }

    public function clasificar(string $desc, ?array $aprendidos = null): array
    {
        $aprendidos ??= Auth::user()?->business()->first()?->conciliacion['conceptos'] ?? [];
        $k = $this->clave($desc);
        if ($k !== '' && isset($aprendidos[$k])) return ['clase' => $aprendidos[$k], 'aprendido' => true, 'clave' => $k];
        $t = ' ' . mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $desc) ?: $desc) . ' ';
        foreach (self::FABRICA as $clase => $ps) foreach ($ps as $p) if (str_contains($t, $p)) return ['clase' => $clase, 'aprendido' => false, 'clave' => $k];
        return ['clase' => 'otro', 'aprendido' => false, 'clave' => $k];
    }

    // Clasificar un concepto una vez: queda para todos los extractos de la empresa.
    public function aprender(ExtractoItem $it, string $clase): void
    {
        abort_unless(isset(self::CLASES[$clase]), 422);
        $b = Auth::user()->business()->first(); $cfg = $b->conciliacion ?? [];
        $cfg['conceptos'][$this->clave($it->descripcion)] = $clase; $b->conciliacion = $cfg; $b->save();
    }

    // La cadena de saldos: saldo anterior + cada movimiento tiene que dar el saldo del renglón siguiente. Devuelve los renglones que no cierran.
    public function cadena(array $filas): array
    {
        $conSaldo = array_values(array_filter($filas, fn($f) => $f['saldo'] !== null));
        if (count($conSaldo) < 2) return ['controlable' => false, 'cierra' => null, 'rotos' => []];
        $rotos = [];
        for ($i = 1; $i < count($conSaldo); $i++) {
            $esperado = round($conSaldo[$i - 1]['saldo'] + $conSaldo[$i]['monto'], 2);
            // El banco puede listar al revés (lo más nuevo arriba): se prueba también en ese sentido.
            $alReves = round($conSaldo[$i]['saldo'] + $conSaldo[$i - 1]['monto'], 2);
            if (abs($esperado - $conSaldo[$i]['saldo']) > 0.02 && abs($alReves - $conSaldo[$i - 1]['saldo']) > 0.02) $rotos[] = ['fecha' => $conSaldo[$i]['fecha'], 'descripcion' => $conSaldo[$i]['descripcion'], 'esperado' => $esperado, 'leido' => $conSaldo[$i]['saldo']];
        }
        return ['controlable' => true, 'cierra' => ! $rotos, 'rotos' => array_slice($rotos, 0, 10), 'cantidad_rotos' => count($rotos)];
    }

    public function cajones(CuentaFondos $cuenta): array
    {
        $aprendidos = Auth::user()?->business()->first()?->conciliacion['conceptos'] ?? [];
        $conc = ExtractoItem::where('cuenta_fondos_id', $cuenta->id)->where('estado', 'conciliado');
        $pend = ExtractoItem::where('cuenta_fondos_id', $cuenta->id)->where('estado', 'pendiente')->orderBy('fecha')->get();
        $soloSistema = MovimientoFondos::where('cuenta_fondos_id', $cuenta->id)->where('conciliado', false)->orderBy('fecha')->get();
        $grupos = [];
        foreach ($pend as $it) {
            $c = $this->clasificar($it->descripcion, $aprendidos);
            $grupos[$c['clase']]['items'][] = ['id' => $it->id, 'fecha' => $it->fecha->format('d/m/Y'), 'descripcion' => $it->descripcion, 'monto' => (float) $it->monto, 'aprendido' => $c['aprendido']];
        }
        $orden = array_keys(self::CLASES);
        $grupos = collect($grupos)->map(fn($g, $k) => ['clase' => $k, 'nombre' => self::CLASES[$k], 'es_gasto' => in_array($k, self::SON_GASTO, true), 'cantidad' => count($g['items']), 'monto' => round(array_sum(array_column($g['items'], 'monto')), 2), 'items' => $g['items']])->sortBy(fn($g) => array_search($g['clase'], $orden))->values()->all();
        $ult = ExtractoBancario::where('cuenta_fondos_id', $cuenta->id)->latest('id')->first();
        $libro = (float) $cuenta->saldo; $faltaRegistrar = round($pend->sum(fn($i) => (float) $i->monto), 2); $enLaCalle = round($soloSistema->sum(fn($m) => (float) $m->ingreso - (float) $m->egreso), 2);
        $extracto = $ult?->saldo_final !== null ? (float) $ult->saldo_final : null;
        $dif = $extracto === null ? null : round($libro + $faltaRegistrar - $enLaCalle - $extracto, 2);
        // Centralizadora: el banco se movió mucho y el fondo casi nada en el período del último extracto.
        $avisoCentral = null;
        if ($ult && $ult->desde && $ult->hasta) {
            $nSis = MovimientoFondos::where('cuenta_fondos_id', $cuenta->id)->whereBetween('fecha', [$ult->desde, $ult->hasta])->count();
            if ($ult->items >= 10 && $nSis <= max(2, (int) ($ult->items * 0.1))) $avisoCentral = "En el período del extracto el fondo {$cuenta->nombre} tiene sólo {$nSis} movimientos contra {$ult->items} del banco: puede que la contabilidad del banco se lleve en otra empresa o esté atrasada.";
        }
        return [
            'en_los_dos' => ['cantidad' => (clone $conc)->count(), 'monto' => round((float) (clone $conc)->sum('monto'), 2)],
            'solo_sistema' => ['cantidad' => $soloSistema->count(), 'monto' => $enLaCalle, 'items' => $soloSistema->take(100)->map(fn($m) => ['id' => $m->id, 'fecha' => $m->fecha->format('d/m/Y'), 'concepto' => $m->concepto, 'monto' => round((float) $m->ingreso - (float) $m->egreso, 2), 'dias' => $m->fecha->diffInDays(today()), 'origen' => $m->origen])->values()->all()],
            'solo_banco' => ['cantidad' => $pend->count(), 'monto' => $faltaRegistrar, 'grupos' => $grupos, 'para_cargar' => round(collect($grupos)->where('es_gasto', true)->sum('monto'), 2)],
            'puente' => ['libro' => $libro, 'falta_registrar' => $faltaRegistrar, 'en_la_calle' => $enLaCalle, 'extracto' => $extracto, 'diferencia' => $dif, 'texto' => $extracto === null ? 'Importá un extracto con saldo para armar el puente.' : 'Libro ' . number_format($libro, 2, ',', '.') . ' + lo que falta registrar ' . number_format($faltaRegistrar, 2, ',', '.') . ' − lo que está en la calle ' . number_format($enLaCalle, 2, ',', '.') . ' = ' . number_format($libro + $faltaRegistrar - $enLaCalle, 2, ',', '.') . ($dif !== null && abs($dif) < 0.01 ? ' = saldo del extracto ✓' : ' · el extracto dice ' . number_format($extracto, 2, ',', '.') . ': diferencia sin explicar ' . number_format($dif, 2, ',', '.'))],
            'aviso_centralizadora' => $avisoCentral,
            'cadena' => $ult?->cadena,
        ];
    }

    // Registrar de una vez todo un grupo de gastos del banco (ej. todos los impuestos del mes), con su categoría. Todo o nada.
    public function registrarGrupo(CuentaFondos $cuenta, string $clase, ?int $categoriaId): int
    {
        abort_unless(in_array($clase, self::SON_GASTO, true), 422, 'Sólo se registran en grupo los gastos bancarios.');
        $svc = app(ConciliacionService::class); $n = 0;
        DB::transaction(function () use ($cuenta, $clase, $categoriaId, $svc, &$n) {
            $categoriaId ??= ExpenseCategory::firstOrCreate(['business_id' => $cuenta->business_id, 'name' => ['impuesto' => 'Impuestos bancarios', 'comision' => 'Gastos bancarios', 'iva' => 'Gastos bancarios', 'interes' => 'Intereses'][$clase]])->id;
            foreach (ExtractoItem::where('cuenta_fondos_id', $cuenta->id)->where('estado', 'pendiente')->get() as $it) {
                if ($this->clasificar($it->descripcion)['clase'] !== $clase) continue;
                $svc->registrarDesdeExtracto($it, $categoriaId); $n++;
            }
            if (! $n) throw ValidationException::withMessages(['clase' => 'No hay renglones pendientes de ese grupo.']);
        });
        \App\Models\AuditLog::registrar('crear', $cuenta, "Registró desde el extracto {$n} renglones de " . self::CLASES[$clase]);
        return $n;
    }
}
