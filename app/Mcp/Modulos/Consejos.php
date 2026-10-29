<?php

namespace App\Mcp\Modulos;

use App\Mcp\Busca;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\Envio;
use App\Models\User;
use App\Services\Estadisticas\QueHacerService;
use App\Services\Estadisticas\TableroService;
use App\Services\Estadisticas\VigilanciaService;
use Carbon\Carbon;

// Fase 40.1 y 40.2: respuestas que sirven y consejos que cruzan los datos. Cada una dice el período y el método,
// y trae "_tabla" (para bajar a Excel) y "_grafico" (barras) cuando ayudan; el panel de Bigcito los muestra.
class Consejos
{
    private const FACT = ['FA', 'FB', 'FC', 'FE', 'FX', 'NDA', 'NDB', 'NDC'];
    private const NC = ['NCA', 'NCB', 'NCC', 'NCX'];

    // Ventas netas de un mes (facturas y notas de débito menos notas de crédito).
    private static function ventasMes(Carbon $ini): float
    {
        $q = fn() => Comprobante::ventas()->emitidos()->whereBetween('fecha', [$ini->toDateString(), $ini->copy()->endOfMonth()->toDateString()]);
        return round((float) $q()->whereIn('tipo', self::FACT)->sum('total') - (float) $q()->whereIn('tipo', self::NC)->sum('total'), 2);
    }

    // Próximos meses con estacionalidad: la base es el promedio de los últimos 3 meses sacándoles la estacionalidad,
    // y cada mes futuro se multiplica por su índice (cuánto vendió ese mes el año pasado contra el promedio de ese año).
    public static function proyeccion(int $meses = 3): array
    {
        $hoy = today(); $mesActual = $hoy->copy()->startOfMonth();
        $hist = collect(range(24, 1))->mapWithKeys(fn($i) => [$mesActual->copy()->subMonths($i)->format('Y-m') => self::ventasMes($mesActual->copy()->subMonths($i))]);
        $conDatos = $hist->filter(fn($v) => $v > 0)->count();
        $ultimoAnio = $hist->slice(-12); $promAnio = $ultimoAnio->avg() ?: 0;
        $estacional = $conDatos >= 12 && $promAnio > 0;
        $indice = fn(Carbon $m) => $estacional ? (($hist[$m->copy()->subYear()->format('Y-m')] ?? 0) / $promAnio ?: 1) : 1;
        $ult3 = collect(range(3, 1))->map(fn($i) => $mesActual->copy()->subMonths($i));
        $base = $ult3->map(fn($m) => ($hist[$m->format('Y-m')] ?? 0) / max(0.05, $indice($m)))->avg();
        $mesEnCurso = app(TableroService::class)->proyeccionMes();
        $futuros = collect(range(1, $meses))->map(function ($i) use ($mesActual, $base, $indice) { $m = $mesActual->copy()->addMonths($i); return ['mes' => $m->locale('es')->isoFormat('MMMM YYYY'), 'proyeccion' => round($base * $indice($m), 2), 'indice' => round($indice($m), 2)]; })->all();
        $como = 'Mes en curso: ' . $mesEnCurso['como'] . ' Próximos meses: el promedio de los últimos 3 meses' . ($estacional ? ' sin la estacionalidad, multiplicado por cuánto pesa cada mes en el año (según el año pasado).' : '. Todavía no hay 12 meses de ventas para calcular la estacionalidad: se usa el promedio parejo.');
        $etiq = array_merge($hist->slice(-6)->keys()->map(fn($k) => Carbon::createFromFormat('Y-m', $k)->locale('es')->isoFormat('MMM YY'))->all(), [$hoy->locale('es')->isoFormat('MMM YY') . ' (proy.)'], array_map(fn($f) => $f['mes'] . ' (proy.)', $futuros));
        $vals = array_merge($hist->slice(-6)->values()->all(), [$mesEnCurso['proyeccion'] ?? $mesEnCurso['hasta_ayer'] + $mesEnCurso['hoy']], array_column($futuros, 'proyeccion'));
        return ['mes_en_curso' => ['mes' => $mesEnCurso['mes'], 'vendido' => $mesEnCurso['hasta_ayer'] + $mesEnCurso['hoy'], 'proyeccion' => $mesEnCurso['proyeccion'], 'contra_mes_pasado' => $mesEnCurso['var_mes_pasado'], 'contra_anio_pasado' => $mesEnCurso['var_anio_pasado']],
            'proximos' => $futuros, 'metodo' => $como, 'meses_con_datos' => $conDatos,
            '_grafico' => ['tipo' => 'barras', 'titulo' => 'Ventas por mes (lo proyectado al final)', 'etiquetas' => $etiq, 'valores' => $vals],
            '_tabla' => ['columnas' => ['Mes', 'Ventas'], 'filas' => array_map(null, $etiq, $vals)]];
    }

    // Deudas por antigüedad: cuánto te deben por tramos de días vencidos, y los que más deben en cada uno.
    public static function antiguedad(): array
    {
        $hoy = today(); $tramos = ['por_vencer' => 'Por vencer', 'd30' => 'Vencido hasta 30 días', 'd60' => '31 a 60 días', 'd90' => '61 a 90 días', 'mas90' => 'Más de 90 días'];
        $tot = array_fill_keys(array_keys($tramos), 0.0); $por = [];
        foreach (Comprobante::ventas()->pendientesCobro()->with('contact:id,name')->get(['id', 'contact_id', 'saldo', 'fecha_vto', 'fecha']) as $c) {
            $d = $c->fecha_vto ? (int) $c->fecha_vto->diffInDays($hoy, false) : 0;
            $k = $d <= 0 ? 'por_vencer' : ($d <= 30 ? 'd30' : ($d <= 60 ? 'd60' : ($d <= 90 ? 'd90' : 'mas90')));
            $tot[$k] += (float) $c->saldo; $n = $c->contact?->name ?? 'Sin cliente';
            $por[$n] ??= array_fill_keys(array_keys($tramos), 0.0); $por[$n][$k] += (float) $c->saldo;
        }
        $filas = collect($por)->map(fn($t, $n) => array_merge([$n], array_map(fn($v) => round($v, 2), array_values($t)), [round(array_sum($t), 2)]))->sortByDesc(fn($f) => end($f))->values();
        return ['al' => $hoy->format('d/m/Y'), 'tramos' => collect($tramos)->map(fn($l, $k) => ['tramo' => $l, 'monto' => round($tot[$k], 2)])->values()->all(), 'total' => round(array_sum($tot), 2), 'mas_deben' => $filas->take(10)->map(fn($f) => ['cliente' => $f[0], 'total' => end($f), 'mas_de_90' => $f[5]])->all(),
            '_grafico' => ['tipo' => 'barras', 'titulo' => 'Lo que te deben por antigüedad', 'etiquetas' => array_values($tramos), 'valores' => array_map(fn($v) => round($v, 2), array_values($tot))],
            '_tabla' => ['columnas' => array_merge(['Cliente'], array_values($tramos), ['Total']), 'filas' => $filas->all()]];
    }

    // ¿A quién le cobro primero? Lo vencido pesa más cuanto más viejo, y suma si hace mucho que no se le avisó.
    public static function aQuienCobrar(int $limite = 15): array
    {
        $hoy = today();
        $deudas = Comprobante::ventas()->pendientesCobro()->where('fecha_vto', '<', $hoy->toDateString())->get(['contact_id', 'saldo', 'fecha_vto'])->groupBy('contact_id');
        $ult = Envio::whereIn('contact_id', $deudas->keys())->where('tipo', 'recordatorio')->selectRaw('contact_id, MAX(created_at) as u')->groupBy('contact_id')->pluck('u', 'contact_id');
        $cli = Contact::whereIn('id', $deudas->keys())->get(['id', 'name', 'phone', 'mobile'])->keyBy('id');
        $filas = $deudas->map(function ($g, $cid) use ($hoy, $ult, $cli) {
            $vencido = (float) $g->sum('saldo'); $dias = (int) $g->max(fn($c) => $c->fecha_vto->diffInDays($hoy));
            $sinAviso = isset($ult[$cid]) ? (int) Carbon::parse($ult[$cid])->diffInDays($hoy) : 999;
            $puntaje = $vencido * (1 + $dias / 30) * ($sinAviso > 15 ? 1.3 : 1);
            return ['cliente' => $cli[$cid]->name ?? "Cliente {$cid}", 'vencido' => round($vencido, 2), 'dias' => $dias, 'ultimo_aviso' => isset($ult[$cid]) ? Carbon::parse($ult[$cid])->format('d/m') : 'nunca', 'telefono' => $cli[$cid]->mobile ?? $cli[$cid]->phone ?? null, 'puntaje' => round($puntaje)];
        })->sortByDesc('puntaje')->values()->take($limite);
        return ['clientes' => $filas->all(), 'criterio' => 'Primero lo que más pesa: el saldo vencido, multiplicado por lo viejo que es (cada 30 días suma una vez más) y un 30 % más si no se le avisó en las últimas dos semanas.',
            '_tabla' => ['columnas' => ['Cliente', 'Vencido', 'Días', 'Último aviso', 'Teléfono'], 'filas' => $filas->map(fn($f) => [$f['cliente'], $f['vencido'], $f['dias'], $f['ultimo_aviso'], $f['telefono']])->all()]];
    }

    public static function resumenDelDia(User $u): array
    {
        $hoy = app(TableroService::class)->hoy();
        $cobrado = (float) \App\Models\Cobro::whereDate('fecha', today())->where('estado', '!=', 'anulado')->sum('total');
        $gastos = (float) \App\Models\MovimientoFondos::whereDate('fecha', today())->where('origen', 'gasto')->sum('egreso');
        $h = app(QueHacerService::class)->hallazgos($u->business);
        return ['dia' => $hoy['dia'] ?? today()->format('d/m'), 'hora' => $hoy['hora'], 'ventas' => $hoy['ventas'], 'facturas' => $hoy['facturas'], 'contra_semana_pasada' => $hoy['var_semana'], 'contra_promedio' => $hoy['var_promedio'], 'cobrado' => round($cobrado, 2), 'gastos' => round($gastos, 2),
            'vencido_por_cobrar' => round((float) Comprobante::ventas()->pendientesCobro()->where('fecha_vto', '<', today()->toDateString())->sum('saldo'), 2),
            'para_mirar' => collect($h['hallazgos'])->where('grave', true)->map(fn($x) => $x['titulo'] . ': ' . $x['texto'])->values()->all(),
            'metodo' => 'Ventas de hoy hasta esta hora contra el mismo día de la semana pasada a la misma hora, y contra el promedio de las últimas 4 semanas.'];
    }

    // Fase 40.3: ventas a "Consumidor final" (o sin cliente) que en el receptor dicen un DNI, CUIT o nombre de un cliente que sí está cargado.
    // Primero por documento (seguro); después, si hay IA, por el nombre parecido.
    public static function sinCliente(int $dias = 90): array
    {
        $cf = Contact::where('name', \App\Support\Sql::like(), '%consumidor final%')->pluck('id')->all();
        $comps = Comprobante::ventas()->emitidos()->where('fecha', '>=', today()->subDays($dias)->toDateString())->where(fn($q) => $q->whereNull('contact_id')->orWhereIn('contact_id', $cf ?: [0]))->whereNotNull('receptor')->latest('fecha')->limit(300)->get();
        $clientes = Contact::customers()->where('is_active', true)->whereNotIn('id', $cf ?: [0])->get(['id', 'name', 'cuit']);
        $porDoc = []; foreach ($clientes as $c) { $d = preg_replace('/\D/', '', (string) $c->cuit); if (strlen($d) === 11) { $porDoc[$d] = $c; $porDoc[substr($d, 2, 8)] = $c; } elseif (strlen($d) >= 7) $porDoc[$d] = $c; }
        $norm = fn($x) => trim(preg_replace('/\s+/', ' ', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', (string) $x) ?: (string) $x)));
        $porNombre = $clientes->keyBy(fn($c) => $norm($c->name));
        $out = []; $dudosos = [];
        foreach ($comps as $c) {
            $doc = preg_replace('/\D/', '', (string) ($c->receptor['documento'] ?? '')); $nom = (string) ($c->receptor['nombre'] ?? '');
            $m = $porDoc[$doc] ?? (strlen($doc) === 11 ? ($porDoc[substr($doc, 2, 8)] ?? null) : null); $como = $m ? 'documento' : null;
            if (! $m && $nom !== '' && ($x = $porNombre[$norm($nom)] ?? null)) { $m = $x; $como = 'nombre igual'; }
            $fila = ['comprobante_id' => $c->id, 'comprobante' => "{$c->nombreTipo()} {$c->numeroFormateado()}", 'fecha' => $c->fecha->format('d/m/Y'), 'total' => (float) $c->total, 'receptor' => trim($nom . ($doc ? " ({$doc})" : ''))];
            if ($m) $out[] = $fila + ['cliente_id' => $m->id, 'cliente' => $m->name, 'como' => $como]; elseif ($nom !== '') $dudosos[] = $fila + ['nombre' => $nom];
        }
        // Con IA: los nombres escritos distinto ("J. Perez" → "Juan Pérez").
        if ($dudosos && ($key = config('services.anthropic.api_key'))) {
            try {
                $r = \Illuminate\Support\Facades\Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(40)->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model'), 'max_tokens' => 1500, 'messages' => [['role' => 'user', 'content' => "Estos comprobantes no tienen cliente pero el receptor dice un nombre:\n" . json_encode(collect($dudosos)->take(60)->map(fn($d) => ['id' => $d['comprobante_id'], 'nombre' => $d['nombre']])->values(), JSON_UNESCAPED_UNICODE) . "\nMis clientes:\n" . json_encode($clientes->take(500)->map(fn($c) => ['id' => $c->id, 'n' => $c->name])->values(), JSON_UNESCAPED_UNICODE) . "\nDevolvé SOLO JSON {\"asignar\":[{\"id\":comprobante,\"cliente\":id}]} con los que sean claramente la misma persona o empresa. Si dudás, no lo pongas."]]]);
                $t = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode(''); $j = json_decode(substr($t, strpos($t, '{'), strrpos($t, '}') - strpos($t, '{') + 1), true) ?: [];
                foreach ($j['asignar'] ?? [] as $a) { $d = collect($dudosos)->firstWhere('comprobante_id', (int) ($a['id'] ?? 0)); $cl = $clientes->firstWhere('id', (int) ($a['cliente'] ?? 0)); if ($d && $cl) $out[] = collect($d)->except('nombre')->all() + ['cliente_id' => $cl->id, 'cliente' => $cl->name, 'como' => 'parecido (IA)']; }
            } catch (\Throwable) {}
        }
        return ['encontrados' => $out, 'sin_identificar' => count($dudosos) - count(array_filter($out, fn($o) => $o['como'] === 'parecido (IA)')), 'criterio' => 'Por documento del receptor (seguro), por nombre igual y, con IA, por nombre parecido. Sólo se vincula el cliente: el comprobante fiscal no cambia.',
            '_tabla' => ['columnas' => ['Comprobante', 'Fecha', 'Total', 'Receptor', 'Cliente', 'Cómo'], 'filas' => array_map(fn($o) => [$o['comprobante'], $o['fecha'], $o['total'], $o['receptor'], $o['cliente'], $o['como']], $out)]];
    }

    public static function herramientas(): array
    {
        return [
            ['nombre' => 'comprobantes_sin_cliente', 'titulo' => 'Comprobantes sin cliente', 'modulo' => 'comprobantes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Ventas a consumidor final o sin cliente que, por el DNI, CUIT o nombre del receptor, son de un cliente que sí está cargado (con IA reconoce nombres escritos distinto).',
                'parametros' => ['dias' => ['type' => 'integer']],
                'ejecutar' => fn(User $u, array $a) => self::sinCliente(max(7, min(365, (int) ($a['dias'] ?? 90))))],
            ['nombre' => 'vincular_comprobantes_cliente', 'titulo' => 'Vincular comprobantes a su cliente', 'modulo' => 'comprobantes', 'permiso' => 'editar', 'tipo' => 'accion', 'delicada' => true,
                'descripcion' => 'Pasa a la cuenta de cada cliente las ventas que se hicieron a consumidor final pero eran suyas (las que encontró comprobantes_sin_cliente). El comprobante fiscal no cambia.',
                'parametros' => ['dias' => ['type' => 'integer']],
                'proponer' => function (User $u, array $a) {
                    $r = self::sinCliente(max(7, min(365, (int) ($a['dias'] ?? 90))));
                    if (! $r['encontrados']) return ['error' => 'No encontré comprobantes para vincular.'];
                    return ['titulo' => 'Vincular ' . count($r['encontrados']) . ' comprobantes a su cliente', 'detalle' => collect($r['encontrados'])->take(5)->map(fn($o) => "{$o['comprobante']} → {$o['cliente']} ({$o['como']})")->implode(' · '), 'datos' => ['pares' => array_map(fn($o) => [$o['comprobante_id'], $o['cliente_id']], $r['encontrados'])]];
                },
                'confirmar' => function (User $u, array $d) {
                    $n = 0;
                    foreach ($d['pares'] as [$cid, $cli]) { $c = Comprobante::ventas()->find($cid); if (! $c) continue; $antes = $c->contact_id; $c->forceFill(['contact_id' => $cli])->save(); \App\Models\AuditLog::registrar('editar', $c, "Vinculó {$c->numeroFormateado()} al cliente #{$cli} (antes #{$antes}) desde el asistente"); $n++; }
                    return ['texto' => "Listo: {$n} comprobantes vinculados a su cliente."];
                }],
            ['nombre' => 'proyeccion_ventas', 'titulo' => 'Proyección de ventas', 'modulo' => 'estadisticas', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Cómo cierra el mes en curso y cuánto se espera vender los próximos meses (con estacionalidad si hay un año de datos), con el método explicado y gráfico.',
                'parametros' => ['meses' => ['type' => 'integer', 'description' => 'Cuántos meses para adelante (3 por defecto).']],
                'ejecutar' => fn(User $u, array $a) => self::proyeccion(max(1, min(6, (int) ($a['meses'] ?? 3))))],
            ['nombre' => 'deudas_por_antiguedad', 'titulo' => 'Deudas por antigüedad', 'modulo' => 'clientes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Lo que te deben los clientes por tramos (por vencer, hasta 30, 60, 90 y más de 90 días vencido) y quiénes deben más. Con gráfico y planilla.',
                'parametros' => [],
                'ejecutar' => fn(User $u, array $a) => self::antiguedad()],
            ['nombre' => 'a_quien_cobrar_primero', 'titulo' => '¿A quién le cobro primero?', 'modulo' => 'clientes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'La lista de clientes para cobrar en orden: más vencido, más viejo y hace más que no se le avisa, con el teléfono.',
                'parametros' => ['cantidad' => ['type' => 'integer']],
                'ejecutar' => fn(User $u, array $a) => self::aQuienCobrar(max(3, min(40, (int) ($a['cantidad'] ?? 15))))],
            ['nombre' => 'precios_a_revisar', 'titulo' => '¿Qué precio reviso?', 'modulo' => 'stock', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Precios que hay que mirar: los que venden por debajo del costo, los que están bajo el margen mínimo y los que hace mucho que no se tocan (de lo que se vendió en el último mes).',
                'parametros' => [],
                'ejecutar' => function (User $u, array $a) {
                    if (! $u->veCostos()) return ['error' => 'Para ver costos y márgenes tu usuario necesita el permiso de ver costos.'];
                    $r = app(VigilanciaService::class)->preciosARevisar($u->business);
                    $fila = fn($x) => [$x['nombre'] ?? '', $x['precio'] ?? null, $x['costo'] ?? null, $x['margen'] ?? null, $x['ultimo_cambio'] ?? $x['fecha'] ?? null];
                    $todos = collect($r['debajo_costo'] ?? [])->map(fn($x) => ['motivo' => 'bajo el costo'] + $x)->concat(collect($r['bajo_minimo'] ?? [])->map(fn($x) => ['motivo' => 'bajo el mínimo'] + $x));
                    return ['debajo_del_costo' => count($r['debajo_costo'] ?? []), 'bajo_el_minimo' => count($r['bajo_minimo'] ?? []), 'sin_tocar' => collect($r['viejo'] ?? [])->map(fn($v) => count($v))->all(), 'primeros' => $todos->take(15)->map(fn($x) => collect($x)->only(['motivo', 'nombre', 'precio', 'costo', 'margen'])->all())->all(),
                        '_tabla' => ['columnas' => ['Motivo', 'Artículo', 'Precio', 'Costo', 'Margen %'], 'filas' => $todos->map(fn($x) => [$x['motivo'], $x['nombre'] ?? '', $x['precio'] ?? null, $x['costo'] ?? null, $x['margen'] ?? null])->all()]];
                }],
            ['nombre' => 'quien_dejo_de_comprar', 'titulo' => '¿Quién dejó de comprarme?', 'modulo' => 'clientes', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Clientes que compraban seguido (3 facturas o más en los 6 meses anteriores) y no compran hace 60 días, con el teléfono para llamarlos.',
                'parametros' => [],
                'ejecutar' => function (User $u, array $a) {
                    $h = collect(app(QueHacerService::class)->hallazgos($u->business)['hallazgos'])->firstWhere('clave', 'clientes');
                    return ['cantidad' => $h['numero'], 'texto' => $h['texto'], 'clientes' => $h['items'], 'criterio' => '3 facturas o más en los 6 meses anteriores y ninguna en los últimos 60 días (sin consumidor final).',
                        '_tabla' => ['columnas' => ['Cliente', 'Teléfono', 'Facturas antes', 'Última compra'], 'filas' => collect($h['items'])->map(fn($i) => [$i['nombre'] ?? '', $i['telefono'] ?? '', $i['facturas'] ?? '', $i['desde'] ?? $i['ultima'] ?? ''])->all()]];
                }],
            ['nombre' => 'resumen_del_dia', 'titulo' => 'Resumen del día', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Cómo va el día: ventas hasta ahora contra la semana pasada, lo cobrado, los gastos, lo vencido por cobrar y lo que hay que mirar.',
                'parametros' => [],
                'ejecutar' => fn(User $u, array $a) => self::resumenDelDia($u)],
        ];
    }
}
