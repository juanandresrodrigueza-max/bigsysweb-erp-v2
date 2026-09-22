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
            ] : null,
            'empresa' => fn() => $user?->business ? [
                'id'     => $user->business->id,
                'nombre' => $user->business->name,
                'logo'   => $user->business->logo,
                'plan'   => $user->business->activeSubscription?->plan?->name,
            ] : null,
            'sucursales' => fn() => $user ? [
                'actual' => $user->currentLocation ? ['id' => $user->currentLocation->id, 'nombre' => $user->currentLocation->name] : null,
                'lista'  => $user->sucursalesAccesibles()->map(fn($l) => ['id' => $l->id, 'nombre' => $l->name, 'ciudad' => $l->city])->values(),
            ] : null,
            'nav' => fn() => $user ? $this->nav($user) : [],
            'alertas' => fn() => $user?->business_id ? [
                'sin_leer' => Alerta::visiblesPara($user)->noLeidasPor($user->id)->count(),
                'ultimas'  => Alerta::visiblesPara($user)->activas()->latest()->limit(6)->get()
                    ->map(fn($a) => [
                        'id' => $a->id, 'titulo' => $a->titulo, 'detalle' => $a->detalle, 'severidad' => $a->severidad,
                        'url' => $a->url, 'modulo' => $a->modulo, 'hace' => $a->created_at->diffForHumans(),
                        'leida' => in_array($user->id, $a->leida_por ?? [], true),
                    ]),
            ] : ['sin_leer' => 0, 'ultimas' => []],
            'flash' => [
                'success' => fn() => $request->session()->get('success'),
                'error'   => fn() => $request->session()->get('error'),
            ],
        ];
    }

    private function nav($user): array
    {
        $visibles = $user->modulosVisibles();
        $grupos = [];
        foreach (config('erp.modulos') as $key => $m) {
            if (! in_array($key, $visibles, true)) {
                continue;
            }
            $grupos[$m['grupo'] ?? ''][] = [
                'key' => $key, 'label' => $m['label'], 'icono' => $m['icono'], 'ruta' => $m['ruta'], 'disponible' => $m['disponible'],
            ];
        }
        return collect($grupos)->map(fn($items, $label) => ['label' => $label, 'items' => $items])->values()->all();
    }
}
