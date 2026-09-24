<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Catalogo;
use App\Models\Contact;
use App\Models\Envio;
use App\Models\Rubro;
use App\Services\Envios\EnvioService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Stock → Catálogos (Fase 25.3): catálogos por lista de precios para mandar a los clientes.
class CatalogosController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Stock/Catalogos', [
            'catalogos' => Catalogo::latest('id')->get()->map(fn($c) => $c->only('id', 'nombre', 'lista', 'rubros', 'iva_incluido', 'mostrar_stock', 'solo_con_stock', 'mostrar_fotos', 'mostrar_codigo', 'nota', 'vistas', 'activo') + [
                'url' => $c->url(), 'visto' => $c->visto_en?->format('d/m H:i'), 'enviados' => Envio::where('modelo', 'Catalogo')->where('modelo_id', $c->id)->count(),
                'clientes_lista' => Contact::customers()->where('is_active', true)->where('lista_precios', $c->lista)->count(),
            ]),
            'rubros' => Rubro::with('parent')->get()->map(fn($r) => ['id' => $r->id, 'nombre' => $r->nombreCompleto()])->sortBy('nombre')->values(),
            'porLista' => Contact::customers()->where('is_active', true)->selectRaw('lista_precios, count(*) as n')->groupBy('lista_precios')->pluck('n', 'lista_precios'),
        ]);
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $d = $request->validate([
            'nombre' => 'required|string|max:120', 'lista' => 'required|integer|min:1|max:6', 'rubros' => 'nullable|array', 'rubros.*' => 'integer|exists:rubros,id',
            'iva_incluido' => 'boolean', 'mostrar_stock' => 'boolean', 'solo_con_stock' => 'boolean', 'mostrar_fotos' => 'boolean', 'mostrar_codigo' => 'boolean', 'nota' => 'nullable|string|max:500', 'activo' => 'boolean',
        ]);
        $d['rubros'] = ! empty($d['rubros']) ? array_values(array_map('intval', $d['rubros'])) : null;
        $c = $id ? Catalogo::findOrFail($id) : new Catalogo(['user_id' => $request->user()->id]);
        $c->fill($d)->save();
        AuditLog::registrar($id ? 'editar' : 'crear', $c, "Catálogo {$c->nombre} (lista {$c->lista})");
        return back()->with('success', 'Catálogo guardado. Ya podés abrirlo, bajar el PDF o mandarlo.');
    }

    public function eliminar(int $id)
    {
        $c = Catalogo::findOrFail($id);
        $c->delete();
        AuditLog::registrar('eliminar', $c, "Catálogo {$c->nombre}");
        return back()->with('success', 'Catálogo eliminado: su link dejó de funcionar.');
    }

    // Link nuevo: el anterior deja de funcionar (por si se reenvió a quien no correspondía).
    public function renovarLink(int $id)
    {
        $c = Catalogo::findOrFail($id);
        $c->forceFill(['token' => \Illuminate\Support\Str::random(32)])->save();
        return back()->with('success', 'Link renovado. El anterior ya no abre.');
    }

    // Mandar a un cliente: con sus precios (lista del cliente, pactados y descuentos).
    public function enviar(Request $request, int $id, EnvioService $envios)
    {
        $c = Catalogo::findOrFail($id);
        $d = $request->validate(['contact_id' => 'required|integer', 'canal' => 'required|in:mail,whatsapp', 'destino' => 'nullable|string|max:150', 'mensaje' => 'nullable|string|max:2000']);
        $c->contact = Contact::customers()->findOrFail($d['contact_id']);
        $e = $envios->enviar($c, $d['canal'], ($d['destino'] ?? null) ?: ($d['canal'] === 'mail' ? $c->contact->email : ($c->contact->mobile ?: $c->contact->phone)), 'catalogo', $d['mensaje'] ?? null);
        if ($e->estado === 'error') return back()->with('error', 'No se pudo enviar: ' . $e->error);
        if ($e->canal === 'whatsapp' && $e->estado === 'pendiente') return back()->with('success', 'Mensaje listo: se abre WhatsApp con el link del catálogo.')->with('abrir', $e->link)->with('envio_id', $e->id);
        return back()->with('success', $e->canal === 'mail' ? "Catálogo enviado por mail a {$e->destino}." : 'Catálogo enviado por WhatsApp.');
    }

    // Mandar a todos los clientes activos de la lista del catálogo (mail o WhatsApp por API), en cola.
    public function enviarLista(Request $request, int $id)
    {
        $c = Catalogo::findOrFail($id);
        $d = $request->validate(['canal' => 'required|in:mail,whatsapp']);
        $b = $request->user()->business;
        abort_if($d['canal'] === 'whatsapp' && empty($b->whatsapp_settings['token']), 422, 'Para mandar a muchos clientes por WhatsApp hace falta la API de WhatsApp (Configuración → Tienda y canales). Por mail sí se puede.');
        $clientes = Contact::customers()->where('is_active', true)->where('lista_precios', $c->lista)
            ->when($d['canal'] === 'mail', fn($q) => $q->whereNotNull('email')->where('email', '!=', ''), fn($q) => $q->where(fn($w) => $w->whereNotNull('mobile')->orWhereNotNull('phone')))->pluck('id');
        foreach ($clientes as $cid) \App\Jobs\EnviarCatalogoJob::dispatch($c->id, $cid, $d['canal'], $request->user()->id);
        AuditLog::registrar('enviar', $c, "Catálogo {$c->nombre} a {$clientes->count()} clientes de la lista {$c->lista} por {$d['canal']}");
        return back()->with('success', $clientes->count() ? "Se está mandando a {$clientes->count()} clientes de la lista {$c->lista}." : "No hay clientes de la lista {$c->lista} con " . ($d['canal'] === 'mail' ? 'email' : 'teléfono') . '.');
    }
}
