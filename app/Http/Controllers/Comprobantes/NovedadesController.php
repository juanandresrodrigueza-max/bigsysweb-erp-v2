<?php

namespace App\Http\Controllers\Comprobantes;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Novedades de facturación: cada vez que un usuario cambió el precio de lista al facturar queda registrado acá.
class NovedadesController extends Controller
{
    public function index(Request $request)
    {
        $desde = $request->desde ?: now()->startOfMonth()->toDateString();
        $hasta = $request->hasta ?: now()->endOfMonth()->toDateString();
        $q = AuditLog::with('user:id,name')->where('accion', 'precio_modificado')->whereBetween('created_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->when($request->usuario, fn($q, $u) => $q->where('user_id', $u))->latest('id');
        $todas = (clone $q)->get();
        $res = ['n' => $todas->count(), 'diferencia' => round($todas->sum(fn($a) => (float) (($a->despues['diferencia'] ?? 0))), 2), 'usuarios' => $todas->groupBy('user_id')->map(fn($g) => ['usuario' => $g->first()->user?->name, 'n' => $g->count(), 'diferencia' => round($g->sum(fn($a) => (float) ($a->despues['diferencia'] ?? 0)), 2)])->sortByDesc('n')->values()];
        return Inertia::render('Comprobantes/Novedades', [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta], 'resumen' => $res,
            'novedades' => $q->paginate(50)->withQueryString()->through(fn($a) => ['id' => $a->id, 'fecha' => $a->created_at->format('d/m/Y H:i'), 'usuario' => $a->user?->name, 'descripcion' => $a->descripcion, 'articulo' => $a->despues['articulo'] ?? null, 'lista' => (float) ($a->despues['lista'] ?? 0), 'facturado' => (float) ($a->despues['facturado'] ?? 0), 'cantidad' => (float) ($a->despues['cantidad'] ?? 0), 'diferencia' => (float) ($a->despues['diferencia'] ?? 0), 'comprobante_id' => $a->modelo_id, 'url' => $a->modelo_id ? "/comprobantes/{$a->modelo_id}" : null]),
            'usuarios' => \App\Models\User::where('business_id', $request->user()->business_id)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
