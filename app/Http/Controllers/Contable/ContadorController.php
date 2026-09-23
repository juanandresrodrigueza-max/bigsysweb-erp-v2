<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Contabilidad\ExportContableService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// Panel del contador: todo lo que el estudio necesita en un lugar, con exportación a Tango, Holistor y Bejerman, y acceso propio de solo lectura.
class ContadorController extends Controller
{
    public function index(Request $request)
    {
        $desde = $request->desde ?: now()->subMonth()->startOfMonth()->toDateString();
        $hasta = $request->hasta ?: now()->subMonth()->endOfMonth()->toDateString();
        $b = $request->user()->business;
        $rolContador = Role::where('slug', 'contador')->first();
        return Inertia::render('Contable/Contador', [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'formatos' => ExportContableService::FORMATOS,
            'contadores' => User::where('business_id', $b->id)->where('role_id', $rolContador?->id)->get()->concat($b->usuariosExternos()->get())->unique('id')->map(fn($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'status' => $u->status, 'externo' => $u->business_id !== $b->id, 'empresas' => $u->empresasAccesibles()->count(), 'ultimo' => $u->last_login_at?->diffForHumans() ?? 'nunca entró']),
            'resumen' => [
                'asientos' => \App\Models\Asiento::where('estado', 'confirmado')->whereBetween('fecha', [$desde, $hasta])->count(),
                'ventas' => \App\Models\Comprobante::ventas()->emitidos()->whereBetween('fecha', [$desde, $hasta])->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'NCA', 'NCB', 'NCC', 'NDA', 'NDB', 'NDC'])->count(),
                'compras' => \App\Models\Comprobante::compras()->emitidos()->whereBetween('fecha', [$desde, $hasta])->count(),
                'retenciones' => \App\Models\Retencion::whereBetween('fecha', [$desde, $hasta])->count(),
            ],
            'checklist' => [
                ['label' => 'Libro IVA ventas y compras', 'url' => "/contable/iva?desde={$desde}&hasta={$hasta}"],
                ['label' => 'Libro IVA Digital (zip para ARCA)', 'url' => "/contable/fiscal?desde={$desde}&hasta={$hasta}"],
                ['label' => 'Retenciones y percepciones (SICORE / SIRCAR)', 'url' => "/contable/fiscal?desde={$desde}&hasta={$hasta}"],
                ['label' => 'Libro diario', 'url' => "/contable/diario?desde={$desde}&hasta={$hasta}"],
                ['label' => 'Mayor', 'url' => "/contable/mayor?desde={$desde}&hasta={$hasta}"],
                ['label' => 'Balance de sumas y saldos', 'url' => "/contable/balance?desde={$desde}&hasta={$hasta}"],
                ['label' => 'Conciliación bancaria', 'url' => '/contable/conciliacion'],
                ['label' => 'Cierre de ejercicio y ajuste por inflación', 'url' => '/contable/ejercicio'],
            ],
        ]);
    }

    public function exportar(Request $request, ExportContableService $svc)
    {
        $d = $request->validate(['formato' => 'required|in:' . implode(',', array_keys(ExportContableService::FORMATOS)), 'desde' => 'required|date', 'hasta' => 'required|date']);
        [$nombre, $contenido, $mime] = $svc->generar($d['formato'], $d['desde'], $d['hasta']);
        AuditLog::registrar('exportar', null, "Exportó {$d['formato']} {$d['desde']} a {$d['hasta']} para el contador");
        return response($contenido, 200, ['Content-Type' => "{$mime}; charset=UTF-8", 'Content-Disposition' => "attachment; filename={$nombre}"]);
    }

    // Da de alta al contador como usuario con el rol Contador (solo lectura de comprobantes, contable completo).
    // Si el email ya existe (el contador ya usa BigSysWeb con otro cliente), se le da acceso a esta empresa sin crear otro usuario.
    public function invitar(Request $request)
    {
        $b = $request->user()->business;
        $rol = Role::withoutGlobalScopes()->where('business_id', $b->id)->where('slug', 'contador')->firstOrFail();
        $existe = User::where('email', mb_strtolower((string) $request->email))->first();
        if ($existe) {
            abort_if($existe->business_id === $b->id, 422, 'Ese usuario ya es de esta empresa.');
            abort_if($existe->is_superadmin, 422, 'Ese email es de un administrador del sistema.');
            $request->validate(['email' => 'required|email']);
            $existe->empresas()->syncWithoutDetaching([$b->id => ['role_id' => $rol->id]]);
            AuditLog::registrar('crear', $existe, "Dio acceso a esta empresa al contador {$existe->email} (usuario existente)");
            return back()->with('success', "Listo: {$existe->name} ya tenía usuario y ahora también ve esta empresa. Le aparece en \"Cambiar empresa\".");
        }
        $d = $request->validate(['name' => 'required|string|max:100', 'email' => ['required', 'email', Rule::unique('users', 'email')], 'password' => 'required|string|min:8']);
        $u = User::create($d + ['business_id' => $b->id, 'role_id' => $rol->id, 'status' => 'active', 'current_location_id' => $b->locations()->where('is_default', true)->value('id') ?? $b->locations()->value('id')]);
        $u->locations()->sync($b->locations()->pluck('id')->mapWithKeys(fn($id) => [$id => ['role_id' => $rol->id]])->all());
        AuditLog::registrar('crear', $u, "Invitó al contador {$u->email}");
        return back()->with('success', "Listo: {$u->name} ya puede entrar con {$u->email} y ver contabilidad, libros y comprobantes.");
    }

    public function quitarAcceso(Request $request, int $userId)
    {
        $b = $request->user()->business;
        $u = User::findOrFail($userId);
        abort_if($u->business_id === $b->id, 422, 'Es un usuario propio de la empresa: desactivalo desde Configuración → Usuarios.');
        $u->empresas()->detach($b->id);
        if ($u->business_id === $b->id) $u->cambiarEmpresa($u->empresasAccesibles()->first());
        AuditLog::registrar('editar', $u, "Quitó el acceso a esta empresa a {$u->email}");
        return back()->with('success', 'Acceso quitado.');
    }
}
