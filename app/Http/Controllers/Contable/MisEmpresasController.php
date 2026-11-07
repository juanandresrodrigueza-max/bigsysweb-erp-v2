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
        $todas = $user->empresasAccesibles(); $est = app(\App\Services\Empresas\EstudioService::class);
        $estudio = $this->estudio($user); $cfg = $est->config($estudio);
        $mails = $estudio->estudio['mails'] ?? [];
        $empresas = $todas->map(fn(Business $b) => $this->resumen($b, $desde, $hasta) + ['email_envio' => $mails[$b->id] ?? $b->email, 'actual' => $b->id === $user->business_id, 'semaforo' => $est->semaforo($b, $mes)]);
        // Fase 39.3: el estudio ve sus clientes (las empresas que administra, sin contar la propia) con el precio progresivo y los vencimientos.
        $clientes = $todas->filter(fn($b) => $b->id !== $estudio->id);
        return Inertia::render('Contable/MisEmpresas', ['empresas' => $empresas->sortBy(fn($e) => ['rojo' => 0, 'amarillo' => 1, 'verde' => 2][$e['semaforo']['nivel']])->values(), 'mes' => $mes, 'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'estudio' => $cfg + ['id' => $estudio->id, 'nombre' => $estudio->name, 'puede_editar' => $estudio->owner_id === $user->id || $user->puede('configuracion', 'editar')], 'precio' => $est->precio($clientes->count(), $cfg['tramos']),
            'vencimientos' => $est->vencimientos($todas, $mes, $cfg['vencimientos']),
            'conjuntos' => \App\Models\ConjuntoEmpresas::where('user_id', $user->id)->orderBy('nombre')->get(['id', 'nombre', 'empresas'])]);
    }

    // La empresa del estudio: la que el usuario marcó como estudio, o la primera de la que es dueño, o la actual.
    private function estudio($user): Business
    {
        $acc = $user->empresasAccesibles();
        return $acc->first(fn($b) => ($b->estudio['es_estudio'] ?? false) && $b->owner_id === $user->id) ?? $acc->first(fn($b) => $b->owner_id === $user->id) ?? $user->business;
    }

    public function guardarEstudio(Request $request)
    {
        $user = $request->user(); $estudio = $this->estudio($user);
        abort_unless($estudio->owner_id === $user->id || $user->puede('configuracion', 'editar'), 403, 'Sólo el dueño del estudio cambia la marca y los precios.');
        $d = $request->validate(['es_estudio' => 'boolean', 'marca' => 'nullable|string|max:120', 'tramos' => 'array|max:6', 'tramos.*.hasta' => 'nullable|integer|min:1', 'tramos.*.precio' => 'required|numeric|min:0', 'vencimientos' => 'array', 'vencimientos.*' => 'integer|min:1|max:31']);
        app(\App\Services\Empresas\EstudioService::class)->guardar($estudio, $d);
        return back()->with('success', 'Datos del estudio guardados.');
    }

    // Fase 45.2: mandarle a la empresa su resumen del mes (texto en el mail o PDF adjunto), con la marca y el logo del estudio. Opcional.
    public function enviar(Request $request, int $id)
    {
        $user = $request->user();
        $b = $user->empresasAccesibles()->firstWhere('id', $id) ?? abort(403, 'No tenés acceso a esa empresa.');
        $d = $request->validate(['email' => 'required|email|max:150', 'formato' => 'required|in:pdf,resumen', 'mes' => 'required|date_format:Y-m', 'mensaje' => 'nullable|string|max:2000']);
        [$desde, $hasta] = [\Carbon\Carbon::parse($d['mes'] . '-01')->toDateString(), \Carbon\Carbon::parse($d['mes'] . '-01')->endOfMonth()->toDateString()];
        $r = $this->resumen($b, $desde, $hasta); $estudio = $this->estudio($user); $marca = app(\App\Services\Empresas\EstudioService::class)->config($estudio)['marca'];
        $periodo = \Carbon\Carbon::parse($d['mes'] . '-01')->locale('es')->translatedFormat('F Y');
        $m = fn($v) => '$ ' . number_format($v, 2, ',', '.');
        $d['mensaje'] = $d['mensaje'] ?? null;
        $texto = ($d['mensaje'] ? $d['mensaje'] . "\n\n" : '') . "Resumen de {$b->name} · {$periodo}\n\n"
            . "Ventas: {$m($r['ventas'])} ({$r['ventas_n']} comprobantes)\nCompras: {$m($r['compras'])} ({$r['compras_n']} comprobantes)\n"
            . "IVA débito: {$m($r['iva_debito'])} · crédito: {$m($r['iva_credito'])}\n" . ($r['iva_saldo'] > 0 ? 'IVA a pagar: ' : 'Saldo a favor: ') . $m(abs($r['iva_saldo'])) . "\n\n{$marca}";
        $adj = null;
        if ($d['formato'] === 'pdf') {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('contable.resumen-empresa', ['r' => $r, 'b' => $b, 'estudio' => $estudio, 'marca' => $marca, 'periodo' => $periodo, 'logo' => $estudio->logoDataUri(), 'mensaje' => $d['mensaje']])->setPaper('a4');
            $adj = [$pdf->output(), 'resumen-' . \Illuminate\Support\Str::slug($b->name) . '-' . $d['mes'] . '.pdf'];
        }
        \Illuminate\Support\Facades\Mail::to($d['email'])->send(new \App\Mail\DocumentoMail($estudio, "Resumen {$periodo} · {$b->name}", $texto, $adj));
        // Se recuerda el mail de cada empresa para la próxima.
        $est = $estudio->estudio ?? []; $est['mails'][$b->id] = $d['email']; $estudio->estudio = $est; $estudio->save();
        \App\Models\AuditLog::registrar('enviar', $b, "Envió el resumen de {$periodo} a {$d['email']}");
        return back()->with('success', "Resumen enviado a {$d['email']}.");
    }

    private function resumen(Business $b, string $desde, string $hasta): array
    {
        $ventas = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'venta')->where('estado', 'emitido')->where('sin_arca', false)->whereBetween('fecha', [$desde, $hasta])->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'NCA', 'NCB', 'NCC', 'NDA', 'NDB', 'NDC']);
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
