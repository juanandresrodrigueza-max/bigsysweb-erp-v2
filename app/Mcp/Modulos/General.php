<?php

namespace App\Mcp\Modulos;

use App\Mcp\Busca;
use App\Models\Alerta;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\Product;
use App\Models\User;

// Panorama del negocio, alertas y la ayuda del sistema.
class General
{
    public static function herramientas(): array
    {
        return [
            ['nombre' => 'resumen_negocio', 'titulo' => 'Resumen del negocio', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Panorama del negocio hoy: ventas del día y del mes, por cobrar y vencido, deuda con proveedores, plata disponible, stock bajo mínimo, lotes por vencer y alertas activas.',
                'ejecutar' => function (User $u, array $a) {
                    $hoy = today();
                    return [
                        'empresa' => $u->business?->name, 'usuario' => $u->name, 'sucursal' => $u->currentLocation?->name, 'fecha' => $hoy->toDateString(),
                        'ventas_hoy' => (float) Comprobante::ventas()->emitidos()->facturas()->whereDate('fecha', $hoy)->sum('total'),
                        'ventas_mes' => (float) Comprobante::ventas()->emitidos()->facturas()->whereBetween('fecha', [$hoy->copy()->startOfMonth()->toDateString(), $hoy->toDateString()])->sum('total'),
                        'por_cobrar' => (float) Contact::customers()->where('balance', '>', 0)->sum('balance'),
                        'vencido_clientes' => (float) Comprobante::ventas()->pendientesCobro()->where('fecha_vto', '<', $hoy->toDateString())->sum('saldo'),
                        'por_pagar' => (float) Contact::suppliers()->where('balance', '>', 0)->sum('balance'),
                        'vencido_proveedores' => (float) Comprobante::compras()->pendientesPago()->where('fecha_vto', '<', $hoy->toDateString())->sum('saldo'),
                        'disponible' => (float) CuentaFondos::where('activa', true)->where('moneda', 'ARS')->sum('saldo'),
                        'articulos_bajo_minimo' => Product::where('active', true)->where('controla_stock', true)->whereColumn('stock', '<=', 'stock_min')->where('stock_min', '>', 0)->count(),
                        'lotes_por_vencer_30_dias' => \App\Models\Lote::where('cantidad', '>', 0)->whereNotNull('vencimiento')->whereBetween('vencimiento', [$hoy->toDateString(), $hoy->copy()->addDays(30)->toDateString()])->count(),
                        'alertas' => Alerta::visiblesPara($u)->activas()->latest()->limit(10)->get(['titulo', 'detalle', 'severidad'])->toArray(),
                    ];
                }],
            ['nombre' => 'alertas_activas', 'titulo' => 'Alertas', 'modulo' => 'alertas', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Alertas activas del sistema (stock bajo, facturas vencidas, cheques, lotes por vencer, cajas abiertas…).',
                'parametros' => ['cantidad' => ['type' => 'integer', 'description' => 'Máximo a devolver (20 por defecto).']],
                'ejecutar' => fn(User $u, array $a) => ['alertas' => Alerta::visiblesPara($u)->activas()->latest()->limit(min(100, (int) ($a['cantidad'] ?? 20)))->get()->map(fn($x) => ['titulo' => $x->titulo, 'detalle' => $x->detalle, 'severidad' => $x->severidad, 'modulo' => $x->modulo, 'url' => $x->url ? Busca::url($x->url) : null, 'desde' => $x->created_at?->toDateTimeString()])->all()]],
            // Fase 59: lo que dicen los documentos adjuntos (contratos, listas de precios, fichas). Devuelve los fragmentos con su fuente; Bigcito arma la respuesta.
            ['nombre' => 'preguntar_documentos', 'titulo' => 'Preguntar a los documentos', 'modulo' => null, 'permiso' => null, 'tipo' => 'consulta',
                'descripcion' => 'Busca en los documentos adjuntos de la empresa (contratos, listas de precios de proveedores, constancias, fichas técnicas, fotos ya leídas) lo que responde una pregunta. Si la pregunta es sobre un cliente, proveedor o artículo, nombralo.',
                'parametros' => ['pregunta' => ['type' => 'string'], 'cliente' => ['type' => 'string'], 'proveedor' => ['type' => 'string'], 'articulo' => ['type' => 'string']], 'requeridos' => ['pregunta'],
                'ejecutar' => function (User $u, array $a) {
                    $type = null; $id = null;
                    if (! empty($a['cliente']) && ($c = Busca::cliente($a['cliente']))) { $type = \App\Models\Contact::class; $id = $c->id; }
                    elseif (! empty($a['proveedor']) && ($c = Busca::proveedor($a['proveedor']))) { $type = \App\Models\Contact::class; $id = $c->id; }
                    elseif (! empty($a['articulo']) && ($p = Busca::articulo($a['articulo']))) { $type = \App\Models\Product::class; $id = $p->id; }
                    $f = app(\App\Services\IA\DocumentosIaService::class)->buscar($u->business, (string) $a['pregunta'], $type, $id, null, 5);
                    if (! $f) return ['fragmentos' => [], 'nota' => 'Ningún documento adjunto habla de eso. No inventes: decile que lo adjunte en Documentos del cliente, proveedor o artículo.'];
                    return ['fragmentos' => collect($f)->map(fn($x) => ['documento' => $x['nombre'], 'de' => $x['de'], 'fecha' => $x['fecha'], 'texto' => mb_substr($x['fragmento'], 0, 1500), 'url' => Busca::url($x['url'])])->all(), 'nota' => 'Respondé con lo que dicen los fragmentos y nombrá el documento de donde lo sacaste.'];
                }],
            // Fase 67: abrir un ticket de soporte por voz; Bigcito lo atiende primero con las guías.
            ['nombre' => 'abrir_ticket_soporte', 'titulo' => 'Escribir a soporte', 'modulo' => null, 'permiso' => null, 'tipo' => 'accion',
                'descripcion' => 'Abre un ticket de soporte a BigSys cuando algo no anda o la ayuda no alcanza. Bigcito lo responde primero con las guías y, si no sabe, lo ve una persona.',
                'parametros' => ['asunto' => ['type' => 'string'], 'mensaje' => ['type' => 'string'], 'categoria' => ['type' => 'string', 'description' => 'consulta, error, sugerencia o facturacion'], 'prioridad' => ['type' => 'string', 'description' => 'baja, normal o alta']],
                'requeridos' => ['asunto', 'mensaje'],
                'proponer' => fn(User $u, array $a) => ['titulo' => 'Abrir el ticket «' . mb_substr($a['asunto'], 0, 80) . '»', 'detalle' => mb_substr($a['mensaje'], 0, 300),
                    'datos' => ['asunto' => mb_substr($a['asunto'], 0, 150), 'mensaje' => mb_substr($a['mensaje'], 0, 4000), 'categoria' => in_array($a['categoria'] ?? '', array_keys(\App\Models\Ticket::CATEGORIAS), true) ? $a['categoria'] : 'consulta', 'prioridad' => in_array($a['prioridad'] ?? '', array_keys(\App\Models\Ticket::PRIORIDADES), true) ? $a['prioridad'] : 'normal']],
                'confirmar' => function (User $u, array $d) {
                    $t = \App\Models\Ticket::create(['business_id' => $u->business_id, 'user_id' => $u->id, 'asunto' => $d['asunto'], 'categoria' => $d['categoria'], 'prioridad' => $d['prioridad'], 'estado' => 'abierto', 'mensajes' => []]);
                    $t->agregar('empresa', $u, $d['mensaje']);
                    $bc = app(\App\Services\IA\SoporteIaService::class)->atender($t, $d['asunto'] . '. ' . $d['mensaje']);
                    return ['texto' => "Ticket {$t->numero()} abierto. " . ($bc['escalado'] ? 'Lo ve el equipo de BigSys.' : 'Bigcito ya dejó una respuesta: ' . mb_substr($bc['texto'], 0, 400)), 'ticket_id' => $t->id, 'url' => Busca::url("/soporte?abrir={$t->id}")];
                }],
            // Fase 68: ¿anda la conexión con el CRM?
            ['nombre' => 'estado_crm', 'titulo' => 'Estado de la conexión con el CRM', 'modulo' => 'configuracion', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Salud de la conexión con el CRM de BigSys: si responde, qué fue lo último que viajó en cada sentido, qué quedó en la cola y los errores recientes.',
                'parametros' => [],
                'ejecutar' => function (User $u, array $a) {
                    $r = app(\App\Services\Integraciones\CrmSaludService::class)->resumen($u->business);
                    return ['estado' => $r['estado'], 'resumen' => $r['texto'], 'ultimo_enviado' => $r['ultimo_enviado'], 'ultimo_recibido' => $r['ultimo_recibido'], 'en_cola' => $r['cola']['pendientes'], 'fallidos' => $r['cola']['fallidos'], 'errores_24h' => $r['errores_24h'],
                        'ultimos' => array_slice($r['ultimos'], 0, 8), 'url' => Busca::url('/configuracion/crm'), 'nota' => $r['cola']['fallidos'] ? 'Hay envíos caídos: en Configuración → CRM está el botón Reintentar.' : null,
                        'plan' => \App\Services\Integraciones\CrmService::plan($u->business), // Fase 75
                        'lo_que_ve_el_crm' => $r['crm'] ? ['estado' => $r['crm']['estado'], 'texto' => $r['crm']['texto'], 'en_cola_hacia_el_erp' => $r['crm']['cola']['pendientes'], 'caidos' => $r['crm']['cola']['fallidos']] : null];
                }],
            // Fase 76: el dueño de BigSys le pregunta a Bigcito cómo está el sistema (sólo superadmin).
            ['nombre' => 'salud_del_sistema', 'titulo' => 'Cómo está el sistema (superadmin)', 'modulo' => null, 'permiso' => null, 'tipo' => 'consulta',
                'descripcion' => 'Sólo para el administrador de BigSys: controles de salud del servidor (base, cola, cron, disco, copias, ARCA), errores de las últimas 24 horas, uso de los últimos 7 días (empresas activas, en riesgo) y copias de seguridad atrasadas. "¿Cómo está el sistema?", "¿hubo errores hoy?".',
                'parametros' => [],
                'ejecutar' => function (User $u, array $a) {
                    if (! $u->is_superadmin) return ['error' => 'Esto lo ve sólo el administrador de BigSys.'];
                    $s = app(\App\Services\Monitoreo\SaludService::class)->verificar();
                    $err = \App\Models\ErrorSistema::where('ultima_vez', '>=', now()->subDay())->whereNull('resuelto_en')->orderByDesc('veces')->limit(5)->get();
                    $uso = app(\App\Services\Producto\UsoService::class)->global(7);
                    $sinCopia = \App\Models\Business::where('is_active', true)->where('backup_auto', true)->get()->filter(fn($b) => ! \App\Models\Backup::withoutGlobalScopes()->where('business_id', $b->id)->where('created_at', '>=', now()->subHours(48))->exists())->pluck('name')->values()->all();
                    return ['estado' => $s['estado'], 'controles_con_problemas' => array_values(array_map(fn($c) => ['control' => $c['nombre'], 'estado' => $c['estado'], 'detalle' => $c['detalle']], array_filter($s['controles'], fn($c) => $c['estado'] !== 'ok'))),
                        'errores_24h' => ['total' => (int) $err->sum('veces'), 'distintos' => $err->count(), 'principales' => $err->map(fn($e) => ['que' => class_basename($e->clase) . ': ' . \Illuminate\Support\Str::limit($e->mensaje, 120), 'donde' => "{$e->archivo}:{$e->linea}", 'ruta' => $e->ruta, 'veces' => $e->veces, 'empresa' => $e->business_id, 'codigo' => substr($e->hash, 0, 8)])->all()],
                        'uso_7_dias' => ['empresas' => $uso['kpis']['empresas'], 'activas' => $uso['kpis']['activas_7'], 'en_riesgo' => array_map(fn($f) => ['empresa' => $f['nombre'], 'sin_uso_dias' => $f['sin_uso_dias']], array_slice($uso['en_riesgo'], 0, 5))],
                        'empresas_sin_copia_48h' => $sinCopia,
                        'copias' => (function () { $e = collect(\App\Services\Migracion\BackupService::estadoPorEmpresa()); return ['con_problemas' => $e->where('verificada', false)->pluck('empresa')->all(), 'ensayo_fallido' => $e->filter(fn($x) => $x['ensayo'] && ! $x['ensayo']['ok'])->pluck('empresa')->all(), 'fuera_del_servidor' => \App\Services\Migracion\BackupService::remotoConfigurado()]; })(), // Fase 78
                        'url' => Busca::url('/admin/salud')];
                }],
            ['nombre' => 'ayuda_sistema', 'titulo' => 'Ayuda del sistema', 'modulo' => null, 'permiso' => null, 'tipo' => 'consulta',
                'descripcion' => 'Busca en las guías de ayuda de BigSysWeb cómo se hace algo en el sistema (pasos, menús, botones).',
                'parametros' => ['pregunta' => ['type' => 'string', 'description' => 'Qué se quiere hacer, en palabras del usuario.']], 'requeridos' => ['pregunta'],
                // Las 3 secciones más cercanas van enteras (pasos completos); el resto, el fragmento. Si no hay nada, lo dice: Bigcito no inventa pantallas.
                'ejecutar' => function (User $u, array $a) {
                    $ay = app(\App\Services\Producto\AyudaService::class); $res = $ay->buscar((string) $a['pregunta'], 5);
                    if (! $res) return ['resultados' => [], 'nota' => 'Las guías no hablan de esto. No inventes pasos: decile que escriba a soporte (Ayuda → Escribir a soporte).'];
                    return ['resultados' => collect($res)->values()->map(function ($r, $i) use ($ay) {
                        $txt = $i < 3 ? (collect($ay->secciones($ay->articulos()[$r['slug']]['texto'] ?? ''))->firstWhere('ancla', $r['ancla'])['texto'] ?? $r['fragmento']) : $r['fragmento'];
                        return ['guia' => $r['titulo'], 'seccion' => $r['seccion'], 'texto' => mb_substr($txt, 0, 2500), 'url' => Busca::url($r['url'])];
                    })->all()];
                }],
        ];
    }
}
