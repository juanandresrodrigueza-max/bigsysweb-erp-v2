<?php

namespace App\Services\Sueldos;

use App\Models\Business;
use App\Models\Empleado;
use App\Models\LiquidacionItem;
use App\Support\Ia;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Fase 65 · Sueldos por texto: las novedades del mes dictadas o pegadas ("Juan hizo 6 horas extra al 50 y faltó 2 días; María 7 días de
// vacaciones y premio 20.000") se vuelven la tabla de novedades por empleado; y el recibo se le explica al empleado en castellano.
class NovedadesTextoService
{
    public const CAMPOS = ['dias', 'feriados', 'vacaciones', 'horas_extra_50', 'horas_extra_100', 'adicionales', 'no_rem_extra', 'anticipos', 'excluir'];

    private static function norm(string $t): string { return Str::lower(Str::ascii($t)); }
    private static function num(string $s): float { $s = str_replace(['$', ' '], '', $s); if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) $s = str_replace('.', '', $s); return (float) str_replace(',', '.', $s); }

    // El texto → novedades por empleado. Por palabras siempre; con clave de IA, la IA resuelve lo que las palabras no (y manda).
    public function interpretar(Business $b, string $texto): array
    {
        $texto = trim($texto); if ($texto === '') return ['ok' => false, 'aviso' => 'Escribí o dictá las novedades.', 'novedades' => [], 'filas' => [], 'no_entendido' => []];
        $empleados = Empleado::withoutGlobalScopes()->where('business_id', $b->id)->where('activo', true)->orderBy('legajo')->get(['id', 'legajo', 'nombre']);
        $r = $this->porPalabras($texto, $empleados);
        if (Ia::tiene($b)) { $ia = $this->conIa($b, $texto, $empleados); if ($ia !== null) { foreach ($ia['novedades'] as $id => $n) $r['novedades'][$id] = array_replace($r['novedades'][$id] ?? [], $n); $r['no_entendido'] = $ia['no_entendido']; $r['modo'] = 'ia'; } }
        foreach ($r['novedades'] as &$n) $n = $this->normalizar($n);
        unset($n);
        $filas = collect($r['novedades'])->map(fn($n, $id) => ['empleado_id' => (int) $id, 'empleado' => $empleados->firstWhere('id', (int) $id)?->nombre] + $n)->values()->all();
        return ['ok' => (bool) $filas, 'modo' => $r['modo'] ?? 'palabras', 'novedades' => $r['novedades'], 'filas' => $filas, 'no_entendido' => $r['no_entendido'], 'aviso' => $filas ? null : 'No reconocí a ningún empleado en el texto. Nombralos como figuran en Sueldos → Empleados.'];
    }

    private function normalizar(array $n): array
    {
        $out = ['dias' => 30, 'feriados' => 0, 'vacaciones' => 0, 'horas_extra_50' => 0, 'horas_extra_100' => 0, 'adicionales' => 0, 'no_rem_extra' => 0, 'anticipos' => 0, 'excluir' => false, 'nota' => null];
        foreach ($out as $k => $v) if (array_key_exists($k, $n) && $n[$k] !== null) $out[$k] = $k === 'excluir' ? (bool) $n[$k] : ($k === 'nota' ? mb_substr((string) $n[$k], 0, 200) : max(0, (float) $n[$k]));
        $faltas = max(0, (float) ($n['faltas'] ?? 0));
        if ($faltas > 0 && empty($n['dias_explicito'])) $out['dias'] = max(0, 30 - $faltas - $out['feriados'] - $out['vacaciones']);
        elseif (empty($n['dias_explicito']) && ($out['feriados'] > 0 || $out['vacaciones'] > 0)) $out['dias'] = max(0, 30 - $out['feriados'] - $out['vacaciones']);
        $out['dias'] = min(31, (int) round($out['dias'])); $out['feriados'] = (int) round($out['feriados']); $out['vacaciones'] = (int) round($out['vacaciones']);
        if ($faltas > 0) $out['nota'] = trim(($out['nota'] ? $out['nota'] . ' · ' : '') . "{$faltas} falta(s)");
        return $out;
    }

    // Parser por palabras: se corta por empleado (cada vez que aparece un nombre) y dentro de cada pedazo se buscan los patrones.
    private function porPalabras(string $texto, $empleados): array
    {
        $t = self::norm($texto); $pos = [];
        foreach ($empleados as $e) {
            $nombres = array_values(array_filter(preg_split('/[\s,]+/', self::norm($e->nombre)), fn($w) => mb_strlen($w) >= 3));
            $claves = array_unique(array_merge([self::norm($e->nombre)], $nombres, $e->legajo ? ["legajo {$e->legajo}", "leg {$e->legajo}"] : []));
            $mejor = null;
            foreach ($claves as $c) if (preg_match('/(?<![a-z0-9])' . preg_quote($c, '/') . '(?![a-z0-9])/', $t, $m, PREG_OFFSET_CAPTURE)) { $p = $m[0][1]; if ($mejor === null || $p < $mejor) $mejor = $p; }
            if ($mejor !== null) $pos[$e->id] = $mejor;
        }
        // Dos empleados que comparten nombre de pila: el pedazo es del que tiene la coincidencia más larga; acá alcanza con el orden.
        asort($pos); $ids = array_keys($pos); $nov = []; $noEnt = [];
        foreach ($ids as $i => $id) {
            $ini = $pos[$id]; $fin = $ids[$i + 1] ?? null; $pedazo = $fin ? mb_substr($t, $ini, $pos[$fin] - $ini) : mb_substr($t, $ini);
            $n = $this->patrones($pedazo);
            if ($n) $nov[$id] = $n; else $noEnt[] = trim($pedazo);
        }
        if (! $ids) $noEnt[] = trim($texto);
        return ['novedades' => $nov, 'no_entendido' => array_values(array_filter($noEnt))];
    }

    private function patrones(string $p): array
    {
        $n = []; $num = '(\d+(?:[.,]\d+)?)';
        if (preg_match_all("/{$num}\s*(?:horas?|hs\.?|h)\s*(?:extras?\s*)?(?:al\s*)?(50|100|cincuenta|cien)\b/u", $p, $m, PREG_SET_ORDER)) foreach ($m as $x) { $k = in_array($x[2], ['100', 'cien'], true) ? 'horas_extra_100' : 'horas_extra_50'; $n[$k] = ($n[$k] ?? 0) + self::num($x[1]); }
        if (preg_match("/{$num}\s*(?:horas?|hs\.?)\s*extras?(?!\s*(?:al\s*)?(?:50|100|cincuenta|cien))/u", $p, $m) && ! isset($n['horas_extra_50']) && ! isset($n['horas_extra_100'])) $n['horas_extra_50'] = self::num($m[1]);
        if (preg_match("/{$num}\s*d[ií]as?\s*(?:de\s*)?vacaciones|vacaciones\s*(?:de\s*|por\s*)?{$num}\s*d[ií]as?/u", $p, $m)) $n['vacaciones'] = self::num($m[1] !== '' ? $m[1] : $m[2]);
        if (preg_match("/{$num}\s*feriados?|feriados?\s*(?:no\s*trabajad[oa]s?\s*)?{$num}?/u", $p, $m)) $n['feriados'] = ($m[1] ?? '') !== '' ? self::num($m[1]) : ((($m[2] ?? '') !== '') ? self::num($m[2]) : 1);
        if (preg_match("/(?:falt[oó]|faltas?|ausencias?|inasistencias?|sin\s*goce)\D{0,12}?{$num}\s*d[ií]as?|{$num}\s*(?:d[ií]as?\s*de\s*)?(?:faltas?|ausencias?|inasistencias?)/u", $p, $m)) $n['faltas'] = self::num(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '0'));
        elseif (preg_match('/\bfalt[oó]\s+un\s+d[ií]a\b/u', $p)) $n['faltas'] = 1;
        if (preg_match("/trabaj[oó]\s*{$num}\s*d[ií]as?|{$num}\s*d[ií]as?\s*trabajad[oa]s?/u", $p, $m)) { $n['dias'] = self::num(($m[1] ?? '') !== '' ? $m[1] : $m[2]); $n['dias_explicito'] = true; }
        if (preg_match("/(?:premio|bono|adicional|comisi[oó]n|comisiones|plus|gratificaci[oó]n|presentismo extra)\D{0,20}?\\$?\s*(\d+(?:[.,]\d+)*)/u", $p, $m)) $n['adicionales'] = self::num($m[1]);
        if (preg_match("/(?:no\s*rem(?:unerativ[oa])?|vi[aá]ticos?|reintegro|comida)\D{0,20}?\\$?\s*(\d+(?:[.,]\d+)*)/u", $p, $m)) $n['no_rem_extra'] = self::num($m[1]);
        if (preg_match("/(?:anticipo|adelanto|a cuenta)\D{0,20}?\\$?\s*(\d+(?:[.,]\d+)*)/u", $p, $m)) $n['anticipos'] = self::num($m[1]);
        if (preg_match('/\b(no se liquida|no liquidar|excluir|sacalo|sacarlo|se fue|renunci[oó]|despedid[oa]|baja)\b/u', $p)) $n['excluir'] = true;
        return $n;
    }

    private function conIa(Business $b, string $texto, $empleados): ?array
    {
        Ia::aplicar($b);
        try {
            $r = Http::withHeaders(['x-api-key' => config('services.anthropic.api_key'), 'anthropic-version' => '2023-06-01'])->timeout(30)->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model'), 'max_tokens' => 1500,
                'system' => "Convertís las novedades del mes de sueldos de una PyME argentina ({$b->name}) en datos por empleado. Devolvé SOLO JSON: {\"novedades\":[{\"empleado_id\":1,\"faltas\":0,\"dias\":null,\"feriados\":0,\"vacaciones\":0,\"horas_extra_50\":0,\"horas_extra_100\":0,\"adicionales\":0,\"no_rem_extra\":0,\"anticipos\":0,\"excluir\":false,\"nota\":null}],\"no_entendido\":[\"frases que no pudiste asignar\"]}. Reglas: dias sólo si dicen explícitamente cuántos días trabajó (si no, null: el sistema calcula 30 menos faltas, feriados y vacaciones); faltas = días que no fue sin justificar; feriados = feriados NO trabajados; horas extra 'al 50' son las comunes y 'al 100' las de sábado tarde/domingo/feriado; adicionales = premios, bonos, comisiones (remunerativos); no_rem_extra = viáticos, reintegros, sumas no remunerativas; anticipos = adelantos ya pagados; excluir si dicen que no se liquida o se fue. Usá ÚNICAMENTE ids de la lista; si una frase no nombra a nadie claro, va a no_entendido.\nEmpleados (id|legajo|nombre):\n" . $empleados->map(fn($e) => "{$e->id}|{$e->legajo}|{$e->nombre}")->implode("\n"),
                'messages' => [['role' => 'user', 'content' => mb_substr($texto, 0, 6000)]]]);
            $j = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode(''))), true);
            if (! is_array($j) || ! isset($j['novedades'])) return null;
            $nov = [];
            foreach ((array) $j['novedades'] as $x) { $id = (int) ($x['empleado_id'] ?? 0); if (! $empleados->firstWhere('id', $id)) continue; $n = array_intersect_key($x, array_flip(array_merge(self::CAMPOS, ['faltas', 'nota']))); if (isset($x['dias']) && $x['dias'] !== null) $n['dias_explicito'] = true; else unset($n['dias']); $nov[$id] = $n; }
            return ['novedades' => $nov, 'no_entendido' => array_values(array_filter(array_map('strval', (array) ($j['no_entendido'] ?? []))))];
        } catch (\Throwable $e) { Log::warning('Novedades IA: ' . $e->getMessage()); return null; }
    }

    // El recibo explicado al empleado, en castellano llano. Con IA un texto corto a medida; sin IA, armado con reglas. Cacheado por recibo.
    public function explicarRecibo(Business $b, LiquidacionItem $item): array
    {
        return Cache::remember("recibo-explicado:{$item->id}:" . ($item->updated_at?->timestamp ?? 0) . ':' . (Ia::tiene($b) ? 'ia' : 'b'), now()->addDays(7), function () use ($b, $item) {
            $f = fn($v) => '$ ' . number_format((float) $v, 0, ',', '.');
            $det = collect($item->detalle ?? []);
            $hab = $det->where('tipo', 'haber'); $nr = $det->where('tipo', 'no_remunerativo'); $ded = $det->where('tipo', 'deduccion');
            $lineas = [];
            $lineas[] = 'Sueldo bruto (lo que ganaste antes de los descuentos): ' . $f($item->bruto) . ($hab->count() ? ' — ' . $hab->map(fn($x) => lcfirst($x['nombre']) . ' ' . $f($x['monto']))->implode(', ') : '') . '.';
            if ((float) $item->no_rem > 0) $lineas[] = 'Sumas no remunerativas (se cobran enteras, no pagan aportes ni cuentan para el aguinaldo): ' . $f($item->no_rem) . ($nr->count() ? ' — ' . $nr->map(fn($x) => lcfirst($x['nombre']))->implode(', ') : '') . '.';
            $lineas[] = 'Descuentos (tus aportes: son para tu jubilación, tu obra social y el PAMI' . ($ded->contains(fn($x) => ($x['grupo'] ?? '') === 'sindicato') ? ', más la cuota del sindicato' : '') . '): ' . $f($item->deducciones) . ($ded->count() ? ' — ' . $ded->map(fn($x) => lcfirst($x['nombre']) . ' ' . $f($x['monto']))->implode(', ') : '') . '.';
            if ((float) $item->anticipos > 0) $lineas[] = 'Anticipos que ya cobraste en el mes y se descuentan: ' . $f($item->anticipos) . '.';
            $lineas[] = 'Neto, lo que te depositan: ' . $f($item->neto) . '.';
            if ((float) $item->contribuciones > 0) $lineas[] = 'Además, la empresa paga ' . $f($item->contribuciones) . ' de contribuciones por vos (no salen de tu sueldo).';
            $basico = implode("\n", $lineas);
            if (! Ia::tiene($b)) return ['texto' => $basico, 'modo' => 'basico'];
            Ia::aplicar($b);
            try {
                $r = Http::withHeaders(['x-api-key' => config('services.anthropic.api_key'), 'anthropic-version' => '2023-06-01'])->timeout(25)->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model'), 'max_tokens' => 500,
                    'system' => "Le explicás su recibo de sueldo a un empleado argentino que no sabe de liquidaciones. Español rioplatense, cálido y claro, sin tecnicismos (si usás uno, explicalo entre paréntesis), máximo 130 palabras, en renglones cortos: qué ganó, qué le descontaron y para qué sirve cada descuento, cuánto cobra en mano. No inventes importes: usá sólo los que te paso. Nada de 'estimado', nada de firmas.",
                    'messages' => [['role' => 'user', 'content' => "Recibo de {$item->empleado?->nombre}, período " . ($item->liquidacion?->periodoLabel() ?? '') . ":\n" . $det->map(fn($x) => "- {$x['nombre']} ({$x['tipo']}): " . $f($x['monto']))->implode("\n") . "\nBruto {$f($item->bruto)} · No rem {$f($item->no_rem)} · Descuentos {$f($item->deducciones)} · Anticipos {$f($item->anticipos)} · Neto {$f($item->neto)}"]]]);
                $t = trim(collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode("\n"));
                return $t !== '' ? ['texto' => $t, 'modo' => 'ia'] : ['texto' => $basico, 'modo' => 'basico'];
            } catch (\Throwable $e) { Log::warning('Recibo explicado IA: ' . $e->getMessage()); return ['texto' => $basico, 'modo' => 'basico']; }
        });
    }
}
