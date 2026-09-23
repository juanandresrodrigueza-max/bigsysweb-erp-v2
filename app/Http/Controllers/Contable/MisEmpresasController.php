<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Comprobante;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

// Panel del contador con varias empresas: el estado de cada una de un vistazo (IVA del mes, pendientes, cierres) y entrar con un clic.
class MisEmpresasController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $mes = $request->mes ?: now()->subMonth()->format('Y-m');
        [$desde, $hasta] = [\Carbon\Carbon::parse($mes . '-01')->toDateString(), \Carbon\Carbon::parse($mes . '-01')->endOfMonth()->toDateString()];
        $empresas = $user->empresasAccesibles()->map(fn(Business $b) => $this->resumen($b, $desde, $hasta) + ['actual' => $b->id === $user->business_id]);
        return Inertia::render('Contable/MisEmpresas', ['empresas' => $empresas->values(), 'mes' => $mes, 'periodo' => ['desde' => $desde, 'hasta' => $hasta]]);
    }

    private function resumen(Business $b, string $desde, string $hasta): array
    {
        $ventas = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'venta')->where('estado', 'emitido')->whereBetween('fecha', [$desde, $hasta])->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'NCA', 'NCB', 'NCC', 'NDA', 'NDB', 'NDC']);
        $compras = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'compra')->where('estado', 'emitido')->whereBetween('fecha', [$desde, $hasta]);
        $signo = fn($q) => (float) $q->clone()->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'NDA', 'NDB', 'NDC'])->sum('iva') - (float) $q->clone()->whereIn('tipo', ['NCA', 'NCB', 'NCC'])->sum('iva');
        $debito = $signo($ventas); $credito = (float) $compras->clone()->sum('iva');
        $sinAsiento = DB::table('comprobantes')->where('business_id', $b->id)->where('estado', 'emitido')->whereBetween('fecha', [$desde, $hasta])->whereNotExists(fn($q) => $q->select(DB::raw(1))->from('asientos')->whereColumn('asientos.origen_id', 'comprobantes.id')->whereColumn('asientos.origen', 'comprobantes.direccion')->where('asientos.estado', 'confirmado'))->count();
        return [
            'id' => $b->id, 'nombre' => $b->name, 'cuit' => $b->cuit, 'condicion_iva' => $b->condicion_iva, 'cierre_mes' => (int) ($b->cierre_ejercicio_mes ?: 12),
            'ventas' => (float) $ventas->clone()->sum('total'), 'ventas_n' => $ventas->clone()->count(), 'compras' => (float) $compras->clone()->sum('total'), 'compras_n' => $compras->clone()->count(),
            'iva_debito' => round($debito, 2), 'iva_credito' => round($credito, 2), 'iva_saldo' => round($debito - $credito, 2),
            'pendientes_cae' => Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('afip_estado', 'pendiente')->count(),
            'sin_asiento' => $sinAsiento,
            'ultimo_backup' => DB::table('backups')->where('business_id', $b->id)->max('created_at'),
        ];
    }
}
