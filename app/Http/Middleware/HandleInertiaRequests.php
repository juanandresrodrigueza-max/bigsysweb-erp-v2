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
            'suscripcion' => fn() => $user?->business ? $this->suscripcion($user) : null,
            'impersonando' => fn() => $request->session()->has('impersonando_desde') ? ['empresa' => $user?->business?->name] : null,
            'mensajeGlobal' => fn() => $user?->business_id ? \App\Models\SistemaConfig::get('mensaje_global') : null,
            'onboarding' => fn() => $user?->business && ! $user->business->onboarding_completado_en && $user->esDueno() ? (function () use ($user) { $p = \App\Http\Controllers\OnboardingController::pasos($user->business); return ['hechos' => count(array_filter($p, fn($x) => $x['hecho'])), 'total' => count($p)]; })() : null,
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
                'pos'     => fn() => $request->session()->get('pos'),
                'abrir'   => fn() => $request->session()->get('abrir'),
                'envio_id' => fn() => $request->session()->get('envio_id'),
                'preview'  => fn() => $request->session()->get('preview'),
                'interpretacion' => fn() => $request->session()->get('interpretacion'),
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
