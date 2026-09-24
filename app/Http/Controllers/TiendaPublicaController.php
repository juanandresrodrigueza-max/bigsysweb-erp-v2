<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Comanda;
use App\Models\Mesa;
use App\Models\PedidoWeb;
use App\Models\Product;
use App\Services\Canales\TiendaService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Páginas públicas de la empresa: tienda (/t/slug), estado del pedido, menú QR (/m/slug) y reservas (/r/slug). Sin login.
class TiendaPublicaController extends Controller
{
    public function __construct(private TiendaService $tienda) {}

    private function negocio(string $slug, string $flag = 'activa')
    {
        $b = TiendaService::porSlug($slug); abort_if(! $b, 404);
        $cfg = $this->tienda->config($b);
        abort_if(! ($cfg[$flag] ?? false), 404, 'Esta página no está habilitada.');
        return [$b, $cfg];
    }

    public function catalogo(string $slug, Request $request)
    {
        [$b, $cfg] = $this->negocio($slug);
        $contact = $request->session()->get('portal_contact_id') ? \App\Models\Contact::withoutGlobalScopes()->find($request->session()->get('portal_contact_id')) : null;
        $cat = $this->tienda->catalogo($b, $contact?->lista_precios);
        return view('tienda.catalogo', ['b' => $b, 'cfg' => $cfg, 'cat' => $cat, 'contact' => $contact]);
    }

    public function pedir(string $slug, Request $request)
    {
        [$b, $cfg] = $this->negocio($slug);
        $d = $request->validate(['items' => 'required|array|min:1', 'items.*.product_id' => 'required|integer', 'items.*.cantidad' => 'required|numeric|min:0.001', 'nombre' => 'required|string|max:120', 'telefono' => 'required|string|max:40', 'email' => 'nullable|email', 'direccion' => 'nullable|string|max:200', 'notas' => 'nullable|string|max:500', 'entrega' => 'required|in:retiro,envio', 'pago' => 'required|in:link,transferencia,efectivo,a_convenir']);
        $contact = $request->session()->get('portal_contact_id') ? \App\Models\Contact::withoutGlobalScopes()->find($request->session()->get('portal_contact_id')) : null;
        $p = $this->tienda->crearPedido($b, ['items' => $d['items'], 'cliente' => ['nombre' => $d['nombre'], 'telefono' => $d['telefono'], 'email' => $d['email'] ?? null, 'direccion' => $d['direccion'] ?? null, 'notas' => $d['notas'] ?? null], 'entrega' => $d['entrega'], 'pago' => $d['pago']], $contact ? 'portal' : 'tienda', $contact);
        return redirect("/t/{$slug}/pedido/{$p->token}");
    }

    public function pedido(string $slug, string $token)
    {
        $b = TiendaService::porSlug($slug); abort_if(! $b, 404);
        $p = PedidoWeb::withoutGlobalScopes()->where('business_id', $b->id)->where('token', $token)->firstOrFail();
        $cfg = $this->tienda->config($b);
        $link = $p->comprobante && $p->comprobante->link_pago ? $p->comprobante->link_pago : ($p->comprobante && $p->pago === 'link' && $p->comprobante->esFactura() && $p->comprobante->estado === 'emitido' ? app(\App\Services\Ventas\LinkPagoService::class)->crear($p->comprobante) : null);
        return view('tienda.pedido', ['b' => $b, 'cfg' => $cfg, 'p' => $p, 'link' => $link]);
    }

    // Menú QR: la carta del local; con ?mesa=ID el cliente pide desde la mesa y cae en la comanda.
    public function menu(string $slug, Request $request)
    {
        [$b, $cfg] = $this->negocio($slug, 'menu_activo');
        $mesa = $request->mesa ? Mesa::withoutGlobalScopes()->where('business_id', $b->id)->where('activa', true)->find($request->mesa) : null;
        $cat = $this->tienda->catalogo($b);
        return view('tienda.menu', ['b' => $b, 'cfg' => $cfg, 'cat' => $cat, 'mesa' => $mesa]);
    }

    public function pedirMesa(string $slug, Request $request)
    {
        [$b, $cfg] = $this->negocio($slug, 'menu_activo');
        $d = $request->validate(['mesa_id' => 'required|integer', 'items' => 'required|array|min:1', 'items.*.product_id' => 'required|integer', 'items.*.cantidad' => 'required|numeric|min:1', 'items.*.notas' => 'nullable|string|max:120', 'nombre' => 'nullable|string|max:60']);
        $mesa = Mesa::withoutGlobalScopes()->where('business_id', $b->id)->findOrFail($d['mesa_id']);
        $comanda = $mesa->comandaAbierta ?? Comanda::create(['business_id' => $b->id, 'business_location_id' => $mesa->business_location_id, 'mesa_id' => $mesa->id, 'user_id' => $b->owner_id, 'numero' => (Comanda::withoutGlobalScopes()->where('business_id', $b->id)->max('numero') ?? 0) + 1, 'tipo' => 'mesa', 'estado' => 'abierta', 'cubiertos' => 2, 'cliente' => $d['nombre'] ?? null, 'total' => 0, 'propina' => 0, 'descuento' => 0, 'abierta_en' => now()]);
        $ri = ($b->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        $ronda = (int) ($comanda->items()->max('ronda') ?? 0) + 1;
        foreach ($d['items'] as $it) {
            $p = Product::withoutGlobalScopes()->where('business_id', $b->id)->find($it['product_id']); if (! $p) continue;
            $al = $ri ? (float) $p->iva : 0;
            $comanda->items()->create(['product_id' => $p->id, 'descripcion' => $p->name, 'cantidad' => $it['cantidad'], 'precio_unit' => round((float) $p->price * (1 + $al / 100), 2), 'alicuota_iva' => $al, 'notas' => trim(($d['nombre'] ?? '') . ' ' . ($it['notas'] ?? '')) ?: null, 'va_cocina' => $p->va_cocina, 'ronda' => $ronda, 'estado' => $p->va_cocina ? 'cocina' : 'listo', 'enviado_en' => now()]);
        }
        $comanda->recalcular();
        \App\Models\Alerta::emitir(['business_id' => $b->id, 'business_location_id' => $mesa->business_location_id, 'modulo' => 'gastronomia', 'tipo' => 'pedido_qr', 'severidad' => 'info', 'titulo' => "Pedido desde el QR de {$mesa->nombre}", 'detalle' => count($d['items']) . ' ítem/s ya en cocina.', 'url' => "/gastronomia/comandas/{$comanda->id}"]);
        return view('tienda.menu_ok', ['b' => $b, 'cfg' => $cfg, 'mesa' => $mesa, 'items' => $d['items'], 'slug' => $slug]);
    }

    public function reservar(string $slug)
    {
        [$b, $cfg] = $this->negocio($slug, 'reservas_activas');
        return view('tienda.reserva', ['b' => $b, 'cfg' => $cfg, 'ok' => session('reserva_ok')]);
    }

    public function reservarStore(string $slug, Request $request)
    {
        [$b, $cfg] = $this->negocio($slug, 'reservas_activas');
        $d = $request->validate(['nombre' => 'required|string|max:120', 'telefono' => 'required|string|max:40', 'email' => 'nullable|email', 'fecha' => 'required|date|after_or_equal:today', 'hora' => 'required|date_format:H:i', 'personas' => 'required|integer|min:1|max:50', 'notas' => 'nullable|string|max:300']);
        $inicio = \Carbon\Carbon::parse($d['fecha'] . ' ' . $d['hora']);
        $ocupadas = (int) Booking::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('status', ['pending', 'confirmed'])->whereBetween('starts_at', [$inicio->copy()->subMinutes(90), $inicio->copy()->addMinutes(90)])->sum('personas');
        if ($ocupadas + $d['personas'] > (int) ($cfg['reservas_capacidad'] ?: 40)) return back()->withErrors(['hora' => 'No queda lugar en ese horario. Probá con otro.'])->withInput();
        $r = Booking::create(['business_id' => $b->id, 'location_id' => $b->locations()->where('is_default', true)->value('id'), 'starts_at' => $inicio, 'ends_at' => $inicio->copy()->addHours(2), 'status' => 'pending', 'price' => 0, 'notes' => $d['notas'] ?? null, 'nombre' => $d['nombre'], 'telefono' => $d['telefono'], 'email' => $d['email'] ?? null, 'personas' => $d['personas'], 'origen' => 'web', 'token' => Str::random(40)]);
        \App\Models\Alerta::emitir(['business_id' => $b->id, 'modulo' => 'gastronomia', 'tipo' => 'reserva_nueva', 'severidad' => 'info', 'titulo' => "Reserva nueva: {$r->nombre} · {$r->personas} personas", 'detalle' => $inicio->format('d/m H:i') . ' · ' . $r->telefono . '. Confirmala desde Gastronomía → Reservas.', 'url' => '/gastronomia/reservas']);
        return back()->with('reserva_ok', ['nombre' => $r->nombre, 'fecha' => $inicio->format('d/m/Y H:i'), 'personas' => $r->personas]);
    }
}
