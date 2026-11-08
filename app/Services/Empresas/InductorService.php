<?php

namespace App\Services\Empresas;

use App\Models\AuditLog;
use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Fase 48 · El inductor de Bigcito: a una empresa nueva le pregunta cómo trabaja según su rubro, deja el sistema a medida
// (rubro, módulos que no usa ocultos, primeros pasos de lo que sí usa) y después mira el uso real para guiarla.
class InductorService
{
    // Cada pregunta: texto, opciones [valor => etiqueta], para qué rubros aplica (null = todos) y qué "uso" declara cada respuesta.
    public static function preguntas(): array
    {
        $sn = ['si' => 'Sí', 'no' => 'No'];
        return [
            'rubro' => ['texto' => '¡Hola! Soy Bigcito. Para dejarte el sistema a medida, contame: ¿a qué se dedica tu negocio?', 'opciones' => Business::VERTICALES, 'rubros' => null],
            'como_vende' => ['texto' => '¿Cómo vendés casi siempre?', 'opciones' => ['mostrador' => 'En el mostrador, cobro en el momento', 'cuenta' => 'Con presupuestos y facturas, muchos a cuenta corriente', 'ambos' => 'Las dos cosas'], 'rubros' => null],
            'arca' => ['texto' => '¿Hacés factura electrónica con ARCA?', 'opciones' => ['si' => 'Sí, y tengo el certificado', 'sin_cert' => 'Sí, pero no tengo el certificado', 'no' => 'No por ahora (solo comprobantes internos)'], 'rubros' => null],
            'stock' => ['texto' => '¿Querés controlar el stock de lo que vendés?', 'opciones' => ['si' => 'Sí, de todo', 'algunos' => 'Solo de algunos artículos', 'no' => 'No, vendo servicios o no lo necesito'], 'rubros' => null],
            'sucursales' => ['texto' => '¿Tenés más de un local, sucursal o depósito?', 'opciones' => ['una' => 'Uno solo', 'varias' => 'Varios'], 'rubros' => null],
            'reparto' => ['texto' => '¿Entregás la mercadería con reparto propio (camión, moto)?', 'opciones' => $sn, 'rubros' => ['corralon', 'minimarket', 'industria', 'retail', 'otro']],
            'tienda' => ['texto' => '¿Vendés por internet (Mercado Libre, Tienda Nube, WooCommerce, Shopify)?', 'opciones' => ['si' => 'Sí', 'quiero' => 'Todavía no, pero quiero', 'no' => 'No'], 'rubros' => ['corralon', 'retail', 'minimarket', 'industria', 'gastronomia', 'otro']],
            'produccion' => ['texto' => '¿Fabricás, armás o elaborás lo que vendés (recetas, armado, fraccionado)?', 'opciones' => $sn, 'rubros' => ['industria', 'gastronomia', 'minimarket', 'otro', 'corralon']],
            'obras' => ['texto' => '¿Trabajás por obra o proyecto (presupuesto, avance, certificados)?', 'opciones' => $sn, 'rubros' => ['corralon', 'servicios', 'industria', 'otro']],
            'agenda' => ['texto' => '¿Trabajás con turnos o una agenda de visitas?', 'opciones' => $sn, 'rubros' => ['servicios', 'hoteleria', 'otro', 'retail']],
            'sueldos' => ['texto' => '¿Querés liquidar los sueldos de tus empleados acá?', 'opciones' => ['si' => 'Sí', 'contador' => 'No, lo hace el contador', 'no' => 'No tengo empleados'], 'rubros' => null],
            'contable' => ['texto' => '¿Y la contabilidad? El sistema hace los asientos solo.', 'opciones' => ['propia' => 'La llevo yo o alguien del equipo', 'contador' => 'La lleva el contador: le doy acceso', 'no' => 'Por ahora no me interesa'], 'rubros' => null],
        ];
    }

    // Las preguntas que corresponden a ese rubro, en orden.
    public function paraRubro(?string $rubro): array
    {
        return array_filter(self::preguntas(), fn($p) => $p['rubros'] === null || ! $rubro || in_array($rubro, $p['rubros'], true));
    }

    // Lo que se deduce de las respuestas: qué módulos opcionales se ocultan, qué usos declaró y qué primeros pasos suma.
    public function perfil(array $r): array
    {
        $usa = fn($k, ...$v) => in_array($r[$k] ?? null, $v, true);
        $ocultos = [];
        foreach (['produccion' => 'produccion', 'obras' => 'obras', 'agenda' => 'agenda'] as $preg => $mod) if (($r[$preg] ?? null) === 'no' || (! isset($r[$preg]) && isset($r['rubro']))) $ocultos[] = $mod;
        if ($usa('sueldos', 'no', 'contador')) $ocultos[] = 'sueldos';
        if ($usa('contable', 'no')) $ocultos[] = 'contable';
        $usos = array_values(array_filter([
            $usa('como_vende', 'mostrador', 'ambos') ? 'mostrador' : null, $usa('como_vende', 'cuenta', 'ambos') ? 'cuenta_corriente' : null,
            $usa('arca', 'si', 'sin_cert') ? 'arca' : null, $usa('stock', 'si', 'algunos') ? 'stock' : null, $usa('sucursales', 'varias') ? 'sucursales' : null,
            $usa('reparto', 'si') ? 'reparto' : null, $usa('tienda', 'si', 'quiero') ? 'tienda' : null, $usa('produccion', 'si') ? 'produccion' : null,
            $usa('obras', 'si') ? 'obras' : null, $usa('agenda', 'si') ? 'agenda' : null, $usa('sueldos', 'si') ? 'sueldos' : null,
            $usa('contable', 'propia', 'contador') ? 'contable' : null, $usa('contable', 'contador') ? 'contador' : null,
        ]));
        return ['ocultos' => array_values(array_unique($ocultos)), 'usos' => $usos];
    }

    // Pasos extra de Primeros pasos según lo que va a usar. 'hecho' se calcula con datos reales.
    public const PASOS = [
        'mostrador' => ['Abrí la caja y probá el mostrador', 'Turno de caja, venta rápida con lector de códigos y cobro en el momento.', '/vender', 'punto-de-venta'],
        'cuenta_corriente' => ['Cargá los saldos de tus clientes', 'Importá los clientes con lo que te deben para arrancar con la cuenta corriente al día.', '/configuracion/importar', 'cobrar'],
        'sucursales' => ['Armá tus sucursales y depósitos', 'Cada local con su caja, su punto de venta y su depósito; los pases de mercadería entre ellos.', '/configuracion/sucursales', 'stock'],
        'reparto' => ['Cargá camiones y choferes', 'Así armás los viajes del día y el chofer entrega con foto desde el celular.', '/comprobantes/entregas', 'ventas-avanzadas'],
        'tienda' => ['Conectá tu tienda online', 'Mercado Libre, Tienda Nube, WooCommerce o Shopify: el stock y los precios viajan solos.', '/configuracion/tienda', 'tienda-y-canales'],
        'produccion' => ['Cargá tus recetas o fórmulas', 'Qué lleva cada producto, para que la producción descuente los insumos.', '/produccion', 'produccion'],
        'obras' => ['Armá tu primera obra', 'Presupuesto, avance y certificados por obra.', '/obras', 'obras'],
        'agenda' => ['Configurá la agenda', 'Los turnos o visitas con recordatorio al cliente.', '/agenda', 'agenda'],
        'sueldos' => ['Cargá tus empleados', 'Con su convenio y su sueldo, para liquidar y mandar los recibos a firmar.', '/sueldos', 'sueldos'],
        'contador' => ['Dale acceso a tu contador', 'Con su mismo email entra a tu empresa desde su panel, con el rol Contador.', '/configuracion/usuarios', 'contable'],
    ];

    // Señales de uso real: con esto se tilda el paso y se detecta lo que dijo que iba a usar y no está usando.
    public function senales(Business $b): array
    {
        $id = $b->id; $q = fn($t) => DB::table($t)->where('business_id', $id);
        $uso = DB::table('uso_diario')->where('business_id', $id)->where('fecha', '>=', today()->subDays(30)->toDateString())->groupBy('modulo')->selectRaw('modulo, SUM(vistas) as v, SUM(acciones) as a')->get()->keyBy('modulo');
        $mod = fn($m) => (int) ($uso[$m]->v ?? 0) + (int) ($uso[$m]->a ?? 0) > 0;
        return [
            'mostrador' => $q('turnos_caja')->exists(),
            'cuenta_corriente' => $q('cobros')->exists() || DB::table('contacts')->where('business_id', $id)->where('balance', '>', 0.005)->exists(),
            'arca' => (bool) $b->afip_cert_path,
            'stock' => $q('stock_movements')->exists(),
            'sucursales' => $q('business_locations')->count() > 1,
            'reparto' => $q('ordenes_entrega')->exists(),
            'tienda' => $q('canales')->exists(),
            'produccion' => $q('production_orders')->exists(),
            'obras' => $mod('obras'),
            'agenda' => $mod('agenda'),
            'sueldos' => $q('empleados')->exists(),
            'contable' => $mod('contable') || $q('asientos')->where('origen', 'manual')->exists(),
            'contador' => DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')->where('users.business_id', $id)->where('roles.slug', 'contador')->exists()
                || DB::table('user_businesses')->join('roles', 'roles.id', '=', 'user_businesses.role_id')->where('user_businesses.business_id', $id)->where('roles.slug', 'contador')->exists(),
        ];
    }

    public function pasosExtra(Business $b): array
    {
        $usos = $b->induccion['usos'] ?? []; if (! $usos) return [];
        $s = $this->senales($b); $out = [];
        foreach ($usos as $u) if (isset(self::PASOS[$u])) { [$t, $d, $url, $ayuda] = self::PASOS[$u]; $out[] = ['key' => "ind_{$u}", 'titulo' => $t, 'desc' => $d, 'url' => $url, 'hecho' => (bool) ($s[$u] ?? false), 'ayuda' => $ayuda, 'bigcito' => true]; }
        return $out;
    }

    // Guarda las respuestas y deja el sistema a medida. Se puede repetir: pisa la anterior.
    public function aplicar(Business $b, array $r): array
    {
        $validas = self::preguntas(); $r = array_filter($r, fn($v, $k) => isset($validas[$k]['opciones'][$v]), ARRAY_FILTER_USE_BOTH);
        $p = $this->perfil($r);
        $b->forceFill(['induccion' => ['respuestas' => $r, 'usos' => $p['usos'], 'fecha' => now()->toDateTimeString()], 'modulos_ocultos' => $p['ocultos']] + (isset($r['rubro']) ? ['vertical' => $r['rubro']] : []))->save();
        AuditLog::registrar('editar', $b, 'Bigcito dejó el sistema a medida: usa ' . (implode(', ', $p['usos']) ?: 'lo básico') . ($p['ocultos'] ? '; oculta ' . implode(', ', $p['ocultos']) : ''));
        return $p;
    }

    // Seguimiento: lo que dijo que iba a usar y a la semana sigue sin arrancar → sugerencia concreta de Bigcito.
    public function seguimiento(Business $b): array
    {
        $ind = $b->induccion ?? []; if (empty($ind['usos'])) return [];
        $dias = isset($ind['fecha']) ? \Carbon\Carbon::parse($ind['fecha'])->diffInDays(now()) : 0;
        $s = $this->senales($b); $out = [];
        foreach ($ind['usos'] as $u) {
            if (($s[$u] ?? true) || ! isset(self::PASOS[$u])) continue;
            [$t, $d, $url] = self::PASOS[$u];
            $out[] = ['uso' => $u, 'titulo' => $t, 'texto' => ($dias >= 7 ? "Me dijiste que ibas a usar esto y todavía no arrancaste. " : '') . $d, 'url' => $url];
        }
        return $out;
    }

    // Texto libre ("tengo un corralón con dos camiones y vendo en Mercado Libre") → respuestas. Sólo valores válidos; lo que no queda claro, se pregunta.
    public function interpretar(string $texto): array
    {
        $key = config('services.anthropic.api_key'); if (! $key) return [];
        $esquema = collect(self::preguntas())->map(fn($p, $k) => "{$k}: " . implode(' | ', array_keys($p['opciones'])))->implode("\n");
        try {
            $res = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'), 'max_tokens' => 400,
                'system' => "Sos Bigcito, el que configura BigSysWeb ERP para un negocio argentino nuevo. Del relato del dueño sacá SOLO las respuestas que se deducen con seguridad. Devolvé SOLO JSON {clave: valor} usando exactamente estas claves y valores:\n{$esquema}\nSi algo no se deduce, no lo pongas.",
                'messages' => [['role' => 'user', 'content' => $texto]],
            ]);
            $raw = collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $d = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $raw)), true);
            $p = self::preguntas();
            return is_array($d) ? array_filter($d, fn($v, $k) => is_string($v) && isset($p[$k]['opciones'][$v]), ARRAY_FILTER_USE_BOTH) : [];
        } catch (\Throwable $e) { Log::warning('Inductor: ' . $e->getMessage()); return []; }
    }

    // Para el contexto de Bigcito en el chat: cómo trabaja esta empresa.
    public function resumen(Business $b): ?string
    {
        $r = $b->induccion['respuestas'] ?? null; if (! $r) return null;
        $p = self::preguntas();
        return collect($r)->map(fn($v, $k) => isset($p[$k]) ? rtrim(preg_replace('/^¡Hola! Soy Bigcito\. Para dejarte el sistema a medida, contame: /', '', $p[$k]['texto']), '?') . ': ' . $p[$k]['opciones'][$v] : null)->filter()->implode('; ');
    }
}
