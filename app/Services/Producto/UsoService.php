<?php

namespace App\Services\Producto;

use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Métricas de uso: qué módulos se usan, quién entra y qué empresas se están enfriando. Un contador por usuario, módulo y día.
class UsoService
{
    // Rutas que no cuentan como uso (consultas automáticas del navegador).
    private const IGNORAR = ['buscar', 'agente', 'alertas', 'up', 'api', 'ayuda', 'sucursal', 'empresa', 'logout', 'login', 'p', 't', 'portal'];

    public static function moduloDeRuta(string $path): ?string
    {
        $seg = explode('/', trim($path, '/'))[0] ?? '';
        if ($seg === '' || in_array($seg, self::IGNORAR, true)) return null;
        if (str_starts_with($path, 'retail/mp/') || str_contains($path, '/mp/estado')) return null;
        foreach (config('erp.modulos') as $key => $m) if (trim($m['ruta'], '/') === $seg) return $key;
        return match ($seg) { 'dueno' => 'dashboard', 'soporte', 'primeros-pasos', 'suscripcion' => 'configuracion', default => null };
    }

    public function registrar(User $user, string $path, bool $accion): void
    {
        $modulo = self::moduloDeRuta($path);
        if (! $modulo || ! $user->business_id) return;
        $col = $accion ? 'acciones' : 'vistas';
        $where = ['business_id' => $user->business_id, 'user_id' => $user->id, 'fecha' => today()->toDateString(), 'modulo' => $modulo];
        if (DB::table('uso_diario')->where($where)->increment($col) === 0) {
            try { DB::table('uso_diario')->insert($where + [$col => 1, 'vistas' => $accion ? 0 : 1, 'acciones' => $accion ? 1 : 0]); }
            catch (\Throwable $e) { DB::table('uso_diario')->where($where)->increment($col); } // carrera: otra petición lo insertó primero
        }
    }

    // Resumen de una empresa en los últimos $dias: módulos, usuarios activos y serie diaria.
    public function empresa(Business|int $b, int $dias = 30): array
    {
        $id = $b instanceof Business ? $b->id : $b;
        $desde = today()->subDays($dias - 1)->toDateString();
        $q = fn() => DB::table('uso_diario')->where('business_id', $id)->where('fecha', '>=', $desde);
        $modulos = $q()->selectRaw('modulo, SUM(vistas) as vistas, SUM(acciones) as acciones, COUNT(DISTINCT user_id) as usuarios')->groupBy('modulo')->orderByDesc('vistas')->get()
            ->map(fn($r) => ['modulo' => $r->modulo, 'label' => config("erp.modulos.{$r->modulo}.label", ucfirst($r->modulo)), 'vistas' => (int) $r->vistas, 'acciones' => (int) $r->acciones, 'usuarios' => (int) $r->usuarios])->values()->all();
        $usuarios = $q()->selectRaw('user_id, SUM(vistas) as vistas, SUM(acciones) as acciones, COUNT(DISTINCT fecha) as dias, MAX(fecha) as ultimo')->groupBy('user_id')->orderByDesc('vistas')->get();
        $nombres = User::whereIn('id', $usuarios->pluck('user_id'))->pluck('name', 'id');
        $serie = $q()->selectRaw('fecha, SUM(vistas) as vistas, SUM(acciones) as acciones, COUNT(DISTINCT user_id) as usuarios')->groupBy('fecha')->orderBy('fecha')->get()->keyBy('fecha');
        $dias_serie = [];
        for ($d = today()->subDays($dias - 1); $d->lte(today()); $d->addDay()) { $k = $d->toDateString(); $dias_serie[] = ['fecha' => $d->format('d/m'), 'vistas' => (int) ($serie[$k]->vistas ?? 0), 'acciones' => (int) ($serie[$k]->acciones ?? 0), 'usuarios' => (int) ($serie[$k]->usuarios ?? 0)]; }
        $ultimo = $q()->max('fecha');
        return [
            'dias' => $dias, 'vistas' => (int) $q()->sum('vistas'), 'acciones' => (int) $q()->sum('acciones'),
            'usuarios_activos' => $usuarios->count(), 'dias_activos' => (int) $q()->distinct()->count('fecha'), 'ultimo_uso' => $ultimo,
            'modulos' => $modulos,
            'usuarios' => $usuarios->map(fn($r) => ['id' => $r->user_id, 'nombre' => $nombres[$r->user_id] ?? "Usuario {$r->user_id}", 'vistas' => (int) $r->vistas, 'acciones' => (int) $r->acciones, 'dias' => (int) $r->dias, 'ultimo' => $r->ultimo])->values()->all(),
            'serie' => $dias_serie,
        ];
    }

    // Actividad por usuario de una empresa (para Configuración → Usuarios): [user_id => [vistas, acciones, dias, ultimo]].
    public function porUsuario(int $businessId, int $dias = 30): array
    {
        return DB::table('uso_diario')->where('business_id', $businessId)->where('fecha', '>=', today()->subDays($dias - 1)->toDateString())
            ->selectRaw('user_id, SUM(vistas) as vistas, SUM(acciones) as acciones, COUNT(DISTINCT fecha) as dias, MAX(fecha) as ultimo')->groupBy('user_id')->get()
            ->mapWithKeys(fn($r) => [$r->user_id => ['vistas' => (int) $r->vistas, 'acciones' => (int) $r->acciones, 'dias' => (int) $r->dias, 'ultimo' => $r->ultimo]])->all();
    }

    // Vista global para BigSys: empresas activas, en riesgo (sin uso), ranking y módulos.
    public function global(int $dias = 30): array
    {
        $desde = today()->subDays($dias - 1)->toDateString();
        $porEmpresa = DB::table('uso_diario')->where('fecha', '>=', $desde)->selectRaw('business_id, SUM(vistas) as vistas, SUM(acciones) as acciones, COUNT(DISTINCT user_id) as usuarios, COUNT(DISTINCT fecha) as dias, MAX(fecha) as ultimo')->groupBy('business_id')->get()->keyBy('business_id');
        $empresas = Business::whereNull('suspended_at')->with('subscription')->withCount('users')->orderBy('name')->get();
        $filas = $empresas->map(function ($b) use ($porEmpresa) {
            $u = $porEmpresa[$b->id] ?? null;
            $ultimo = $u?->ultimo ? \Carbon\Carbon::parse($u->ultimo) : null;
            return ['id' => $b->id, 'nombre' => $b->name, 'estado' => $b->subscription?->status, 'usuarios' => (int) $b->users_count, 'usuarios_activos' => (int) ($u->usuarios ?? 0), 'vistas' => (int) ($u->vistas ?? 0), 'acciones' => (int) ($u->acciones ?? 0), 'dias_activos' => (int) ($u->dias ?? 0), 'ultimo' => $ultimo?->format('d/m'), 'sin_uso_dias' => $ultimo ? (int) $ultimo->diffInDays(today()) : null, 'alta' => $b->created_at->format('d/m/Y')];
        });
        $activas7 = DB::table('uso_diario')->where('fecha', '>=', today()->subDays(6)->toDateString())->distinct()->count('business_id');
        $modulos = DB::table('uso_diario')->where('fecha', '>=', $desde)->selectRaw('modulo, SUM(vistas) as vistas, COUNT(DISTINCT business_id) as empresas')->groupBy('modulo')->orderByDesc('vistas')->get()->map(fn($r) => ['modulo' => $r->modulo, 'label' => config("erp.modulos.{$r->modulo}.label", ucfirst($r->modulo)), 'vistas' => (int) $r->vistas, 'empresas' => (int) $r->empresas])->values()->all();
        $serie = DB::table('uso_diario')->where('fecha', '>=', $desde)->selectRaw('fecha, COUNT(DISTINCT business_id) as empresas, COUNT(DISTINCT user_id) as usuarios, SUM(vistas) as vistas')->groupBy('fecha')->orderBy('fecha')->get()->keyBy('fecha');
        $dias_serie = [];
        for ($d = today()->subDays($dias - 1); $d->lte(today()); $d->addDay()) { $k = $d->toDateString(); $dias_serie[] = ['fecha' => $d->format('d/m'), 'empresas' => (int) ($serie[$k]->empresas ?? 0), 'usuarios' => (int) ($serie[$k]->usuarios ?? 0), 'vistas' => (int) ($serie[$k]->vistas ?? 0)]; }
        return [
            'dias' => $dias,
            'kpis' => ['empresas' => $empresas->count(), 'activas_periodo' => $filas->where('vistas', '>', 0)->count(), 'activas_7' => $activas7, 'usuarios_activos' => (int) DB::table('uso_diario')->where('fecha', '>=', $desde)->distinct()->count('user_id'), 'sin_uso_14' => $filas->filter(fn($f) => $f['sin_uso_dias'] === null || $f['sin_uso_dias'] >= 14)->count()],
            'ranking' => $filas->sortByDesc('vistas')->take(15)->values()->all(),
            'en_riesgo' => $filas->filter(fn($f) => ($f['sin_uso_dias'] === null || $f['sin_uso_dias'] >= 7) && in_array($f['estado'], ['active', 'trial', 'grace'], true))->sortByDesc(fn($f) => $f['sin_uso_dias'] ?? 9999)->values()->all(),
            'modulos' => $modulos, 'serie' => $dias_serie,
        ];
    }
}
