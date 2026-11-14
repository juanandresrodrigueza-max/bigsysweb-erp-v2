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
        $mesa = $this->mesaDesde($b, $request->mesa);
        $cat = $this->tienda->catalogo($b);
        // Fase 95: lo agotado hoy se ve como "Agotado" y no se puede pedir; se vuelve a habilitar solo al otro día.
        $agotados = Product::withoutGlobalScopes()->where('business_id', $b->id)->whereNotNull('agotado_hasta')->whereDate('agotado_hasta', '>=', today())->pluck('id')->flip();
        $cat['rubros'] = collect($cat['rubros'])->map(function ($r) use ($agotados) { $r['items'] = collect($r['items'])->map(function ($i) use ($agotados) { $i['agotado'] = isset($agotados[$i['id']]); return $i; })->all(); return $r; })->all();
        // Fase 52: sólo lo que se sirve a esta hora, con el precio de happy hour si corresponde.
        $prods = \App\Models\Product::withoutGlobalScopes()->where('business_id', $b->id)->get(['id', 'horario', 'rubro_id'])->keyBy('id');
        $cat['rubros'] = collect($cat['rubros'])->map(function ($r) use ($prods, $b) {
            $r['items'] = collect($r['items'])->filter(fn($i) => ! isset($prods[$i['id']]) || \App\Services\Gastronomia\HorariosService::disponible($prods[$i['id']]))
                ->map(function ($i) use ($prods, $b) { $hh = isset($prods[$i['id']]) ? \App\Services\Gastronomia\HorariosService::happyHour($b, $prods[$i['id']]) : null; if ($hh) { $i['precio_antes'] = $i['precio']; $i['precio'] = \App\Services\Gastronomia\HorariosService::aplicar((float) $i['precio'], $hh); $i['hh'] = $hh['nombre']; } return $i; })->values()->all();
            return $r;
        })->filter(fn($r) => $r['items'])->values()->all();
        // Fase 56: carta traducida (?lang=en|pt), el agregado que más se pide con cada plato y la encuesta al pedir la cuenta.
        $lang = in_array($request->lang, array_keys(\App\Services\Gastronomia\CartaQrService::IDIOMAS), true) ? $request->lang : 'es';
        $prods = \App\Models\Product::withoutGlobalScopes()->where('business_id', $b->id)->where('en_tienda', true)->get(['id', 'traducciones'])->keyBy('id');
        $idiomas = collect(\App\Services\Gastronomia\CartaQrService::IDIOMAS)->filter(fn($n, $k) => $prods->contains(fn($p) => ! empty($p->traducciones[$k]['nombre'])))->all();
        if ($lang !== 'es') $cat['rubros'] = collect($cat['rubros'])->map(function ($r) use ($prods, $lang) { $r['items'] = collect($r['items'])->map(function ($i) use ($prods, $lang) { $t = $prods[$i['id']]->traducciones[$lang] ?? null; if ($t) { $i['nombre'] = $t['nombre']; $i['descripcion'] = $t['descripcion'] ?? $i['descripcion']; } return $i; })->all(); return $r; })->all();
        $nombres = collect($cat['rubros'])->flatMap(fn($r) => $r['items'])->keyBy('id');
        $sug = $mesa ? collect(app(\App\Services\Gastronomia\CartaQrService::class)->acompanantes($b))->filter(fn($s) => isset($nombres[$s['id']]))->map(fn($s) => ['id' => $s['id'], 'nombre' => $nombres[$s['id']]['nombre'], 'precio' => $nombres[$s['id']]['precio']])->all() : [];
        $cab = $mesa?->comandaAbierta; $opinion = $cab && $cab->items()->exists() ? ['url' => \App\Services\Gastronomia\OpinionesService::link($b, $cab), 'hecha' => \App\Models\RestoOpinion::withoutGlobalScopes()->where('comanda_id', $cab->id)->exists()] : null;
        // Fase 95: si la mesa ya está abierta y este celular no es el que la abrió, tiene que poner el código de la mesa.
        $autorizada = $cab ? $this->autorizada($request, $cab) : true;
        return view('tienda.menu', ['b' => $b, 'cfg' => $cfg, 'cat' => $cat, 'mesa' => $mesa, 'mesaRef' => $mesa?->tokenQr(), 'lang' => $lang, 't' => \App\Services\Gastronomia\CartaQrService::textos($lang), 'idiomas' => $idiomas, 'sug' => $sug, 'opinion' => $opinion,
            'cab' => $cab, 'autorizada' => $autorizada, 'pedido' => $cab && $autorizada ? $this->pedidoArr($cab) : null]);
    }

    public function pedirMesa(string $slug, Request $request)
    {
        [$b, $cfg] = $this->negocio($slug, 'menu_activo');
        $d = $request->validate(['mesa_id' => 'nullable|integer', 'mesa' => 'nullable|string|max:40', 'codigo' => 'nullable|string|max:6', 'items' => 'required|array|min:1', 'items.*.product_id' => 'required|integer', 'items.*.cantidad' => 'required|numeric|min:1', 'items.*.notas' => 'nullable|string|max:120', 'items.*.modificadores' => 'nullable|array', 'nombre' => 'nullable|string|max:60']);
        $mesa = $this->mesaDesde($b, $d['mesa'] ?? $d['mesa_id'] ?? null, false); abort_unless($mesa, 404);
        // Fase 95: mesa ya abierta por otro celular → hace falta el código de la mesa. Platos agotados hoy → no entran.
        if (($cab = $mesa->comandaAbierta) && ! $this->autorizada($request, $cab, $d['codigo'] ?? null)) return back()->withErrors(['codigo' => 'Esta mesa ya está abierta. Pedí el código a tu mesa para sumarte a la cuenta.'])->withInput();
        $ag = Product::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('id', array_column($d['items'], 'product_id'))->whereNotNull('agotado_hasta')->whereDate('agotado_hasta', '>=', today())->pluck('name');
        if ($ag->isNotEmpty()) return back()->withErrors(['agotado' => 'Justo se acabó: ' . $ag->implode(', ') . '. Sacalo del pedido y volvé a enviar.'])->withInput();
        // Primero se arman y validan todas las líneas (opciones obligatorias, precios); recién después se abre la comanda.
        // Así un pedido rechazado no deja una mesa abierta y vacía.
        $ri = ($b->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        $confirma = ! empty($b->gastronomia_config['qr_confirma']);
        $filas = [];
        foreach ($d['items'] as $it) {
            $p = Product::withoutGlobalScopes()->where('business_id', $b->id)->find($it['product_id']); if (! $p) continue;
            $al = $ri ? (float) $p->iva : 0;
            // Fase 51: el pedido del QR respeta las opciones del plato (obligatorias y con precio) y va a la estación que corresponde.
            [$mods, $extra] = \App\Services\Gastronomia\ComandaService::modificadores($p, $it['modificadores'] ?? null);
            $filas[] = ['product_id' => $p->id, 'descripcion' => mb_substr($p->name . ($mods ? ' (' . implode(', ', array_column($mods, 'nombre')) . ')' : ''), 0, 250), 'modificadores' => $mods ?: null, 'estacion' => $p->estacion ?: ($b->estacionesCocina()[0]['nombre'] ?? 'Cocina'),
                'cantidad' => $it['cantidad'], 'precio_unit' => \App\Services\Gastronomia\HorariosService::aplicar(round((float) $p->price * (1 + $al / 100), 2), \App\Services\Gastronomia\HorariosService::happyHour($b, $p)) + $extra, 'alicuota_iva' => $al, 'notas' => trim(($d['nombre'] ?? '') . ' ' . ($it['notas'] ?? '')) ?: null, 'va_cocina' => $p->va_cocina,
                'estado' => $confirma ? 'pedido' : ($p->va_cocina ? 'cocina' : 'listo'), 'enviado_en' => $confirma ? null : now()];
        }
        abort_if(! $filas, 422, 'No hay nada para pedir.');
        $comanda = $cab ?? Comanda::create(['business_id' => $b->id, 'business_location_id' => $mesa->business_location_id, 'mesa_id' => $mesa->id, 'user_id' => $b->owner_id, 'numero' => (Comanda::withoutGlobalScopes()->where('business_id', $b->id)->max('numero') ?? 0) + 1, 'tipo' => 'mesa', 'estado' => 'abierta', 'cubiertos' => 2, 'cliente' => $d['nombre'] ?? null, 'total' => 0, 'propina' => 0, 'descuento' => 0, 'abierta_en' => now()]);
        $ronda = (int) ($comanda->items()->max('ronda') ?? 0) + 1;
        foreach ($filas as $f) $comanda->items()->create($f + ['ronda' => $ronda]);
        $comanda->recalcular();
        $request->session()->put("mesa_ok.{$comanda->id}", true); // este celular ya es de la mesa: no le pide el código
        \App\Models\Alerta::emitir(['business_id' => $b->id, 'business_location_id' => $mesa->business_location_id, 'modulo' => 'gastronomia', 'tipo' => 'pedido_qr', 'severidad' => 'info', 'titulo' => "Pedido desde el QR de {$mesa->nombre}", 'detalle' => count($d['items']) . ($confirma ? ' ítem/s esperando que lo confirmes.' : ' ítem/s ya en cocina.'), 'url' => "/gastronomia/comandas/{$comanda->id}"]);
        \App\Services\Gastronomia\AvisosSalonService::avisar($b, $mesa->business_location_id, "Pedido en {$mesa->nombre}", count($d['items']) . ' ítem/s desde el QR' . ($confirma ? ': confirmalo para que salga a cocina.' : ', ya en cocina.'), "/gastronomia/comandas/{$comanda->id}");
        return view('tienda.menu_ok', ['b' => $b, 'cfg' => $cfg, 'mesa' => $mesa, 'mesaRef' => $mesa->tokenQr(), 'items' => $d['items'], 'slug' => $slug, 'codigo' => $comanda->codigo, 'confirma' => $confirma]);
    }

    // Fase 51: desde el QR, "Llamar al mozo" o "Pedir la cuenta". Aparece en el salón y como aviso.
    public function llamarMozo(string $slug, Request $request)
    {
        [$b] = $this->negocio($slug, 'menu_activo');
        $d = $request->validate(['mesa_id' => 'nullable|integer', 'mesa' => 'nullable|string|max:40', 'codigo' => 'nullable|string|max:6', 'motivo' => 'required|in:mozo,cuenta']);
        $mesa = $this->mesaDesde($b, $d['mesa'] ?? $d['mesa_id'] ?? null); abort_unless($mesa, 404);
        $c = $mesa->comandaAbierta;
        if ($c && ! $this->autorizada($request, $c, $d['codigo'] ?? null)) return back()->withErrors(['codigo' => 'Esta mesa ya está abierta. Pedí el código a tu mesa.']);
        if ($c) $c->update(['llamado' => $d['motivo'], 'llamado_en' => now()] + ($d['motivo'] === 'cuenta' ? ['estado' => 'cuenta', 'cuenta_en' => now()] : []));
        $titulo = ($d['motivo'] === 'cuenta' ? 'Piden la cuenta en ' : 'Llaman al mozo en ') . $mesa->nombre;
        \App\Models\Alerta::emitir(['business_id' => $b->id, 'business_location_id' => $mesa->business_location_id, 'modulo' => 'gastronomia', 'tipo' => 'llamado_mesa', 'severidad' => 'aviso', 'titulo' => $titulo, 'detalle' => 'Desde el QR de la mesa.', 'url' => $c ? "/gastronomia/comandas/{$c->id}" : '/gastronomia']);
        \App\Services\Gastronomia\AvisosSalonService::avisar($b, $mesa->business_location_id, $titulo, 'Desde el QR de la mesa' . ($c ? ' · ' . number_format((float) $c->total, 0, ',', '.') . ' $ en la cuenta.' : '.'), $c ? "/gastronomia/comandas/{$c->id}" : '/gastronomia/mozo');
        return back()->with('llamado', $d['motivo']);
    }

    // Fase 95: el segundo celular de la mesa pone el código de 4 dígitos y queda autorizado para pedir y ver la cuenta.
    public function codigoMesa(string $slug, Request $request)
    {
        [$b] = $this->negocio($slug, 'menu_activo');
        $d = $request->validate(['mesa' => 'required|string|max:40', 'codigo' => 'required|string|max:6']);
        $mesa = $this->mesaDesde($b, $d['mesa']); abort_unless($mesa, 404);
        $c = $mesa->comandaAbierta;
        if (! $c || ! $this->autorizada($request, $c, $d['codigo'])) return back()->withErrors(['codigo' => 'Ese código no es de esta mesa. Fijate en el celular de quien pidió primero o preguntale al mozo.']);
        return back()->with('success', 'Listo, ya podés pedir a la misma cuenta.');
    }

    // Fase 95: "lo que pidieron", en vivo: el celular lo consulta cada 15 segundos. Sólo si el celular es de la mesa.
    public function pedidoMesa(string $slug, Request $request)
    {
        [$b] = $this->negocio($slug, 'menu_activo');
        $mesa = $this->mesaDesde($b, $request->mesa); abort_unless($mesa, 404);
        $c = $mesa->comandaAbierta;
        if (! $c) return response()->json(['abierta' => false]);
        if (! $this->autorizada($request, $c)) return response()->json(['abierta' => true, 'autorizada' => false]);
        return response()->json(['abierta' => true, 'autorizada' => true] + $this->pedidoArr($c));
    }

    private function mesaDesde(\App\Models\Business $b, $ref, bool $soloActivas = true): ?Mesa
    {
        if (! $ref) return null;
        $q = Mesa::withoutGlobalScopes()->where('business_id', $b->id)->when($soloActivas, fn($q) => $q->where('activa', true));
        // El QR nuevo trae el token; los QR viejos (?mesa=id) siguen abriendo hasta que los reimpriman.
        return (clone $q)->where('token', (string) $ref)->first() ?? (ctype_digit((string) $ref) ? (clone $q)->find((int) $ref) : null);
    }

    private function autorizada(Request $request, Comanda $c, ?string $codigo = null): bool
    {
        if ($request->session()->get("mesa_ok.{$c->id}")) return true;
        // Abierta por el mozo pero sin nada pedido todavía: el primer celular que pide la toma, sin código.
        if (! $c->items()->where('estado', '!=', 'anulado')->exists()) { $request->session()->put("mesa_ok.{$c->id}", true); return true; }
        if ($codigo !== null && $c->codigo && hash_equals((string) $c->codigo, trim($codigo))) { $request->session()->put("mesa_ok.{$c->id}", true); return true; }
        return false;
    }

    private function pedidoArr(Comanda $c): array
    {
        $its = $c->items()->where('estado', '!=', 'anulado')->get();
        $etiqueta = fn($i) => match ($i->estado) { 'pedido' => 'Esperando confirmación', 'cocina' => $i->empezado_en ? 'En marcha' : ($i->va_cocina ? 'En cocina' : 'Pedido'), 'listo' => 'Listo', 'entregado' => 'Servido', default => $i->estado };
        return ['codigo' => $c->codigo, 'estado' => $c->estado, 'llamado' => $c->llamado, 'total' => (float) $c->total,
            'items' => $its->map(fn($i) => ['descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'importe' => round((float) $i->cantidad * (float) $i->precio_unit, 2), 'estado' => $i->estado, 'etiqueta' => $etiqueta($i)])->values()->all()];
    }

    // Fase 56: la encuesta de 10 segundos. Llega desde el QR de la mesa o desde el WhatsApp de "pedido listo"; el link viene firmado.
    public function opinion(string $slug, Request $request)
    {
        [$b, $cfg] = $this->negocio($slug, 'menu_activo');
        $c = Comanda::withoutGlobalScopes()->where('business_id', $b->id)->find((int) $request->c);
        abort_unless($c && hash_equals(\App\Services\Gastronomia\OpinionesService::firma($c), (string) $request->f), 404);
        $lang = in_array($request->lang, array_keys(\App\Services\Gastronomia\CartaQrService::IDIOMAS), true) ? $request->lang : 'es';
        $hecha = \App\Models\RestoOpinion::withoutGlobalScopes()->where('comanda_id', $c->id)->exists();
        if ($request->isMethod('post') && ! $hecha) {
            $d = $request->validate(['puntaje' => 'required|integer|min:1|max:5', 'comentario' => 'nullable|string|max:500']);
            app(\App\Services\Gastronomia\OpinionesService::class)->guardar($b, $c, (int) $d['puntaje'], $d['comentario'] ?? null, $request->canal === 'wa' ? 'whatsapp' : 'qr');
            if ((int) $d['puntaje'] <= 2) \App\Models\Alerta::emitir(['business_id' => $b->id, 'modulo' => 'gastronomia', 'tipo' => 'opinion_mala', 'modelo' => 'Comanda', 'modelo_id' => $c->id, 'severidad' => 'aviso', 'titulo' => "Opinión de {$d['puntaje']} estrellas en {$c->titulo()}", 'detalle' => mb_substr((string) ($d['comentario'] ?? 'Sin comentario'), 0, 300), 'url' => '/estadisticas/gastronomia']);
            $hecha = true;
        }
        return view('tienda.opinion', ['b' => $b, 'cfg' => $cfg, 'c' => $c, 'hecha' => $hecha, 'lang' => $lang, 't' => \App\Services\Gastronomia\CartaQrService::textos($lang), 'slug' => $slug, 'f' => $request->f, 'canal' => $request->canal]);
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
