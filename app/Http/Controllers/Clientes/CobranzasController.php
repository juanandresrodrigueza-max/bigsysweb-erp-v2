<?php

namespace App\Http\Controllers\Clientes;

use App\Http\Controllers\Controller;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\Envio;
use App\Models\PlanPago;
use App\Services\Ventas\CobranzasService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CobranzasController extends Controller
{
    public function __construct(private CobranzasService $service) {}

    public function index(Request $request)
    {
        $b = $request->user()->business;
        PlanPago::where('estado', 'vigente')->get()->each(fn($p) => $this->service->actualizarCuotas($p));
        $deudores = $this->service->deudores();
        return Inertia::render('Clientes/Cobranzas', [
            'deudores' => $deudores,
            'kpis' => $this->service->resumenDeudores(),
            'config' => $this->service->config($b),
            'whatsapp' => ['token' => $b->whatsapp_settings['token'] ?? '', 'phone_id' => $b->whatsapp_settings['phone_id'] ?? '', 'configurado' => ! empty($b->whatsapp_settings['token'])],
            'mailConfigurado' => config('mail.default') !== 'log',
            'bancos' => \App\Models\CuentaFondos::whereIn('tipo', ['banco', 'billetera'])->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']),
            'clientesLista' => \App\Models\Contact::customers()->where('is_active', true)->where('name', '!=', 'Consumidor Final')->orderBy('name')->limit(3000)->get(['id', 'name'])->map(fn($c) => ['id' => $c->id, 'nombre' => $c->name]),
            'crmActivo' => \App\Services\Integraciones\CrmService::activo($request->user()->business),
            'envios' => Envio::with('contact:id,name')->whereIn('tipo', ['recordatorio'])->latest()->limit(30)->get()->map(fn($e) => ['id' => $e->id, 'fecha' => $e->created_at->format('d/m H:i'), 'cliente' => $e->contact?->name, 'canal' => $e->canal, 'destino' => $e->destino, 'estado' => $e->estado, 'link' => $e->link, 'error' => $e->error]),
            'planes' => PlanPago::with(['contact:id,name', 'cuotas'])->orderByDesc('id')->limit(20)->get()->map(fn($p) => ['id' => $p->id, 'numero' => $p->numeroFormateado(), 'cliente' => $p->contact?->name, 'fecha' => $p->fecha->format('d/m/Y'), 'total' => (float) $p->total, 'interes' => (float) $p->interes, 'estado' => $p->estado, 'cuotas' => $p->cuotas->map(fn($q) => ['numero' => $q->numero, 'vencimiento' => $q->vencimiento->format('d/m/Y'), 'monto' => (float) $q->monto, 'pagado' => (float) $q->pagado, 'estado' => $q->estado])]),
        ]);
    }

    public function configurar(Request $request)
    {
        $d = $request->validate(['activo' => 'boolean', 'dias' => 'nullable|array', 'dias.*' => 'integer|min:-30|max:180', 'canales' => 'nullable|array', 'canales.*' => 'in:mail,whatsapp,crm', 'texto' => 'nullable|string|max:1000', 'whatsapp_token' => 'nullable|string|max:500', 'whatsapp_phone_id' => 'nullable|string|max:60']);
        $b = $request->user()->business;
        $b->recordatorios = ['activo' => $d['activo'] ?? false, 'dias' => array_values(array_unique(array_map('intval', $d['dias'] ?? []))), 'canales' => $d['canales'] ?? ['mail'], 'texto' => $d['texto'] ?: CobranzasService::DEFAULT['texto']];
        $b->whatsapp_settings = ['token' => $d['whatsapp_token'] ?? ($b->whatsapp_settings['token'] ?? ''), 'phone_id' => $d['whatsapp_phone_id'] ?? ($b->whatsapp_settings['phone_id'] ?? '')];
        $b->save();
        return back()->with('success', 'Configuración de cobranzas guardada.');
    }

    // Recordatorio manual de todas las facturas vencidas del cliente por el canal elegido (una por factura más vieja).
    public function recordar(Request $request, int $contactId)
    {
        $d = $request->validate(['canal' => 'required|in:mail,whatsapp,crm']);
        $c = Contact::findOrFail($contactId);
        $f = Comprobante::where('contact_id', $c->id)->pendientesCobro()->orderBy('fecha_vto')->get()->first(fn($x) => $x->vencido()) ?? Comprobante::where('contact_id', $c->id)->pendientesCobro()->orderBy('fecha_vto')->first();
        abort_if(! $f, 422, 'El cliente no tiene comprobantes pendientes.');
        $e = $this->service->recordar($f, $d['canal']);
        if ($e->estado === 'error') return back()->with('error', 'No se pudo enviar: ' . $e->error);
        if ($e->canal === 'whatsapp' && $e->estado === 'pendiente') return back()->with('success', 'Recordatorio listo en WhatsApp.')->with('abrir', $e->link)->with('envio_id', $e->id);
        return back()->with('success', "Recordatorio enviado a {$e->destino}.");
    }

    public function refinanciar(Request $request, int $contactId)
    {
        $d = $request->validate(['comprobantes' => 'required|array|min:1', 'cuotas' => 'required|integer|min:1|max:36', 'interes_pct' => 'nullable|numeric|min:0|max:500', 'primer_vencimiento' => 'required|date', 'notas' => 'nullable|string|max:500']);
        $p = $this->service->refinanciar(Contact::customers()->findOrFail($contactId), $d);
        return back()->with('success', "Plan {$p->numeroFormateado()} creado: $ " . number_format((float) $p->total, 2, ',', '.') . " en {$p->cuotas_n} cuotas.");
    }

    public function correr()
    {
        $n = $this->service->correrAutomaticos();
        return back()->with('success', "Se enviaron {$n} recordatorios según la configuración.");
    }

    // Fase 47: la foto o el PDF del comprobante de transferencia → la IA lo lee y propone el recibo.
    public function leerTransferencia(Request $request, \App\Services\Comprobantes\TransferenciaClienteService $t)
    {
        $request->validate(['archivo' => 'required|file|max:10240|mimes:jpg,jpeg,png,webp,pdf']);
        $f = $request->file('archivo'); $mime = $f->getMimeType() === 'application/pdf' ? 'application/pdf' : ($f->getMimeType() ?: 'image/jpeg');
        return response()->json(app(\App\Services\Comprobantes\TransferenciaClienteService::class)->leerComprobante(file_get_contents($f->getRealPath()), $mime));
    }

    public function desdeTransferencia(Request $request, \App\Services\Comprobantes\TransferenciaClienteService $t)
    {
        $d = $request->validate(['contact_id' => 'required|integer', 'monto' => 'required|numeric|min:0.01', 'fecha' => 'required|date', 'cuenta_fondos_id' => 'required|integer', 'referencia' => 'nullable|string|max:80']);
        $c = \App\Models\Contact::customers()->findOrFail($d['contact_id']); $cuenta = \App\Models\CuentaFondos::whereIn('tipo', ['banco', 'billetera'])->findOrFail($d['cuenta_fondos_id']);
        $cobro = $t->recibo($c, (float) $d['monto'], $d['fecha'], $cuenta->id, $d['referencia'] ?? null, 'Desde el comprobante de transferencia');
        // Si el extracto ya estaba importado, se concilia en el acto.
        app(\App\Services\Contabilidad\ConciliacionService::class)->conciliarAutomatico($cuenta);
        return back()->with('success', "Recibo {$cobro->numeroFormateado()} hecho a {$c->name} por $ " . number_format($d['monto'], 2, ',', '.') . '.');
    }
}
