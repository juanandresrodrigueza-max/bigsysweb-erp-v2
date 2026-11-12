<?php

namespace App\Http\Middleware;

use App\Models\Alerta;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => fn() => $user ? [
                'user' => [
                    'id'            => $user->id,
                    'name'          => $user->name,
                    'email'         => $user->email,
                    'avatar'        => $user->avatar,
                    'rol'           => $user->rolActual()?->nombre ?? ($user->esDueno() ? 'Dueño' : null),
                    'es_dueno'      => $user->esDueno(),
                    'is_superadmin' => $user->is_superadmin,
                ],
                'permisos' => $user->permisosResumen(),
                'gastronomia' => in_array('gastronomia', $user->modulosVisibles(), true),
                'ia' => \App\Support\Ia::tiene($user->business), // Fase 58
                'partesEstadisticas' => $user->esDueno() || $user->is_superadmin ? null : $user->rolActual()?->vePartes(),
            ] : null,
            'empresa' => fn() => $user?->business ? [
                'id'     => $user->business->id,
                'nombre' => $user->business->name,
                'logo'   => $user->business->logo,
                'plan'   => $user->business->activeSubscription?->plan?->name,
            ] : null,
            'sucursales' => fn() => $user ? [
                'actual' => $user->currentLocation ? ['id' => $user->currentLocation->id, 'nombre' => $user->currentLocation->name] : null,
                'lista'  => $user->sucursalesAccesibles()->map(fn($l) => ['id' => $l->id, 'nombre' => $l->name, 'ciudad' => $l->city, 'cuit' => $l->cuit])->values(),
                'consolidado' => (bool) $user->ver_consolidado, 'puede_consolidar' => $user->esDueno() || $user->puede('estadisticas'),
            ] : null,
            'nav' => fn() => $user ? $this->nav($user) : [],
            'subnav' => fn() => $user && $user->business_id ? $this->subnav($user, $request) : null, // Fase 80
            'tip' => fn() => $user && $user->business_id && ! $user->is_superadmin ? \App\Services\Producto\AyudaService::tipDelDia($user) : null, // Fase 81
            // Contador con varias empresas: selector en el encabezado.
            'empresas' => fn() => $user && ($user->business_id) ? (function () use ($user) { $l = $user->empresasAccesibles(); return $l->count() > 1 ? $l->map(fn($b) => ['id' => $b->id, 'nombre' => $b->name, 'cuit' => $b->cuit])->values() : null; })() : null,
            'tour' => fn() => $user && $user->business_id && ! $user->tour_visto_en ? \App\Services\Producto\AyudaService::tour() : null,
            'suscripcion' => fn() => $user?->business ? $this->suscripcion($user) : null,
            'impersonando' => fn() => $request->session()->has('impersonando_desde') ? ['empresa' => $user?->business?->name] : null,
            'mensajeGlobal' => fn() => $user?->business_id ? \App\Models\SistemaConfig::get('mensaje_global') : null,
            'onboarding' => fn() => $user?->business && ! $user->business->onboarding_completado_en && $user->esDueno() ? (function () use ($user) { $p = \App\Http\Controllers\OnboardingController::pasos($user->business); return ['hechos' => count(array_filter($p, fn($x) => $x['hecho'])), 'total' => count($p)]; })() : null,
            'soporteWhatsapp' => fn() => $user?->business_id ? \App\Models\SistemaConfig::get('soporte_whatsapp') : null,
            'novedades' => fn() => $user?->business_id ? app(\App\Services\Avisos\CelularService::class)->sinLeer($user) : 0,
            'alertas' => fn() => $user?->business_id ? [
                'sin_leer' => Alerta::visiblesPara($user)->noLeidasPor($user->id)->count(),
                'ultimas'  => Alerta::visiblesPara($user)->activas()->latest()->limit(6)->get()
                    ->map(fn($a) => [
                        'id' => $a->id, 'titulo' => $a->titulo, 'detalle' => $a->detalle, 'severidad' => $a->severidad,
                        'url' => $a->url, 'modulo' => $a->modulo, 'hace' => $a->created_at->diffForHumans(),
                        'leida' => in_array($user->id, $a->leida_por ?? [], true),
                    ]),
            ] : ['sin_leer' => 0, 'ultimas' => []],
            'demo' => ! app()->environment('production'),
            'flash' => [
                'success' => fn() => $request->session()->get('success'),
                'error'   => fn() => $request->session()->get('error'),
                'pos'     => fn() => $request->session()->get('pos'),
                'abrir'   => fn() => $request->session()->get('abrir'),
                'crm_secreto_nuevo' => fn() => $request->session()->get('crm_secreto_nuevo'),
                'envio_id' => fn() => $request->session()->get('envio_id'),
                'prueba'   => fn() => $request->session()->get('prueba'), // resultado de "Probar" dentro de la tarjeta que lo pidió (Superadmin → Sistema)
                'preview'  => fn() => $request->session()->get('preview'),
                'interpretacion' => fn() => $request->session()->get('interpretacion'),
                'resumen_texto' => fn() => $request->session()->get('resumen_texto'),
                'totp_setup' => fn() => $request->session()->get('totp_setup'),
                'totp_codigos' => fn() => $request->session()->get('totp_codigos'),
                'token_nuevo' => fn() => $request->session()->get('token_nuevo'),
            ],
        ];
    }

    // Estado de la suscripción para el banner: solo lo ve quien puede resolverlo (dueño o quien edita configuración).
    private function suscripcion($user): ?array
    {
        $sub = $user->business->subscription;
        if (! $sub) {
            return ['estado' => 'sin_plan', 'aviso' => ['nivel' => 'error', 'texto' => 'La empresa no tiene un plan asignado.'], 'puede' => $user->esDueno()];
        }
        return ['estado' => $sub->status, 'plan' => $sub->plan?->name, 'dias' => $sub->diasRestantes(), 'aviso' => $sub->aviso(), 'puede' => $user->esDueno() || $user->puede('configuracion', 'editar')];
    }

    private function nav($user): array
    {
        // Fase 79: el menú sale de erp.menu (orden del día del negocio); el permiso, del módulo de cada entrada.
        $visibles = $user->modulosVisibles();
        // Los grupos son tramos consecutivos del menú (el orden manda): el ciclo sin título, "Mi rubro" con título, y Configuración al final.
        $grupos = [];
        foreach ($this->entradas($visibles) as $m) {
            $g = $m['grupo'] ?? '';
            if (! $grupos || end($grupos)['label'] !== $g) $grupos[] = ['label' => $g, 'items' => []];
            $grupos[array_key_last($grupos)]['items'][] = ['key' => $m['key'], 'label' => $m['label'], 'icono' => $m['icono'], 'ruta' => $m['ruta'], 'modulo' => $m['modulo'], 'disponible' => config("erp.modulos.{$m['modulo']}.disponible", true),
                // Fase 82: las secciones también viajan en el menú, para que Ctrl K encuentre "Liquidaciones" o "Puntos de venta y AFIP".
                // Fase 88: cuelgan del módulo en el menú (con su grupo y su ayuda), así que viajan completas.
                'secciones' => array_map(fn($i) => ['key' => $i['key'], 'label' => $i['label'], 'ruta' => $i['ruta'], 'grupo' => $i['grupo'], 'ayuda' => $i['ayuda']], $this->itemsDe($m['key'], $m, $user, $visibles))];
        }
        // Integración con el CRM activa: botón que pasa al CRM ya logueado (link externo, no Inertia), al final.
        if (\App\Services\Integraciones\CrmService::activo($user->business)) {
            if (! $grupos || end($grupos)['label'] !== '') $grupos[] = ['label' => '', 'items' => []];
            $grupos[array_key_last($grupos)]['items'][] = ['key' => 'crm', 'label' => 'CRM', 'icono' => 'idcard', 'ruta' => '/integraciones/crm/ir', 'modulo' => 'configuracion', 'disponible' => true, 'externo' => true, 'secciones' => []];
        }
        return $grupos;
    }

    // Las entradas del menú que este usuario ve, en orden. Fase 88: cada rubro habilitado es una entrada más (ya no se juntan en "Mi rubro").
    private function entradas(array $visibles): array
    {
        $out = [];
        foreach (config('erp.menu') as $m) {
            if (! in_array($m['modulo'], $visibles, true)) continue;
            if (isset($m['solo_sin']) && in_array($m['solo_sin'], $visibles, true)) continue; // Fase 82: Sueldos vive adentro de Contable
            $out[] = $m;
        }
        return $out;
    }

    // Fase 80: la sub-navegación de la pantalla actual. Gana el ítem de ruta más larga que coincide con la URL (así /stock/pases
    // es de Entregar y no de Stock); las fichas y formularios no la llevan. Se filtra por módulo visible y, si el ítem lo pide, por acción.
    public function subnav($user, Request $request): ?array
    {
        $url = '/' . trim($request->path(), '/');
        $visibles = $user->modulosVisibles();
        $menu = collect($this->entradas($visibles))->keyBy('key');
        $candidatos = []; // [key, ruta] de los ítems que coinciden; gana la ruta más larga y, en empate, la primera entrada visible
        foreach (config('erp.secciones', []) as $key => $items) {
            foreach ($items as $it) {
                if (! empty($it['solo_link'])) continue; // se lista, pero la pantalla pertenece a otro módulo (o va a pantalla completa)
                foreach (array_merge([$it['ruta']], $it['alias'] ?? []) as $r) { // Fase 85: 'alias' = otra URL que es la misma sección (Valores dentro de Cheques y valores)
                    if ($url === $r || (empty($it['exacto']) && str_starts_with($url, $r . '/'))) $candidatos[] = [$key, $r, $it['ruta']];
                }
            }
        }
        if (! $candidatos) return null;
        $max = max(array_map(fn($c) => strlen($c[1]), $candidatos));
        $mejor = null;
        foreach ($candidatos as [$key, $r, $rutaItem]) {
            if (strlen($r) !== $max) continue;
            if (isset($menu[$key])) { $mejor = [$key, $r, $rutaItem]; break; } // las entradas ya vienen filtradas por lo que el usuario ve
        }
        if (! $mejor) return null;
        [$key, $coincidida, $activa] = $mejor;
        $resto = array_filter(explode('/', substr($url, strlen($coincidida))));
        if (array_intersect($resto, config('erp.secciones_sin', []))) return null;
        $entrada = $menu[$key];
        $items = array_map(fn($it) => $it + ['activo' => $it['ruta'] === $activa], $this->itemsDe($key, $entrada, $user, $visibles));
        return ['key' => $key, 'titulo' => $entrada['label'], 'icono' => $entrada['icono'], 'ruta' => $entrada['ruta'], 'items' => $items];
    }

    // Los ítems de una sección que este usuario puede usar (módulo visible y, si el ítem lo pide, la acción).
    private function itemsDe(string $key, array $entrada, $user, array $visibles): array
    {
        $items = [];
        foreach (config("erp.secciones.$key", []) as $it) {
            $modulo = $it['modulo'] ?? $entrada['modulo'];
            if (! in_array($modulo, $visibles, true)) continue;
            if (isset($it['accion']) && ! $user->puede($modulo, $it['accion'])) continue;
            if (isset($it['usa']) && ! $user->business?->usa($it['usa'])) continue; // Fase 85: pantallas de rubro (pesables, simulador)
            $items[] = ['key' => $it['key'], 'label' => $it['label'], 'ruta' => $it['ruta'], 'grupo' => $it['grupo'] ?? '', 'ayuda' => $it['ayuda'] ?? null, 'palabras' => $it['palabras'] ?? ''];
        }
        return $items;
    }
}
