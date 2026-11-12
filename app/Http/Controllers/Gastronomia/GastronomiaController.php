<?php

namespace App\Http\Controllers\Gastronomia;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Cobro;
use App\Models\Comanda;
use App\Models\ComandaItem;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\Mesa;
use App\Models\Product;
use App\Models\Rubro;
use App\Services\Gastronomia\ComandaService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Salón: plano de mesas, comandas, cocina y cierre con factura.
class GastronomiaController extends Controller
{
    // Precio de carta: con IVA incluido si la empresa es responsable inscripto; el monotributista no discrimina.
    private function precioFinal(Product $p): float
    {
        $ri = (auth()->user()->business->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        return round((float) $p->price * ($ri ? 1 + (float) $p->iva / 100 : 1), 2);
    }

    private function comandaArr(Comanda $c): array
    {
        $c->loadMissing('items.product:id,name', 'mesa:id,nombre,sector', 'mozo:id,name');
        return [
            'id' => $c->id, 'numero' => $c->numeroFormateado(), 'titulo' => $c->titulo(), 'tipo' => $c->tipo, 'estado' => $c->estado, 'mesa_id' => $c->mesa_id, 'mesa' => $c->mesa?->nombre, 'sector' => $c->mesa?->sector,
            'mozo' => $c->mozo?->name, 'cubiertos' => $c->cubiertos, 'cliente' => $c->cliente, 'direccion' => $c->direccion, 'telefono' => $c->telefono, 'total' => (float) $c->total, 'descuento' => (float) $c->descuento, 'propina' => (float) $c->propina, 'notas' => $c->notas,
            'abierta' => $c->abierta_en?->format('H:i'), 'minutos' => $c->abierta_en ? (int) $c->abierta_en->diffInMinutes(now()) : 0, 'comprobante_id' => $c->comprobante_id,
            'pagos' => $c->pagos ?? [], 'pendiente' => round($c->items->where('estado', '!=', 'anulado')->sum(fn($i) => ((float) $i->cantidad - (float) $i->pagado) * (float) $i->precio_unit), 2),
            'items' => $c->items->map(fn($i) => ['id' => $i->id, 'pagado' => (float) $i->pagado, 'asiento' => $i->asiento, 'cortesia' => (bool) $i->cortesia, 'cortesia_motivo' => $i->cortesia_motivo, 'modificadores' => $i->modificadores, 'product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'precio_unit' => (float) $i->precio_unit, 'notas' => $i->notas, 'estado' => $i->estado, 'va_cocina' => $i->va_cocina, 'estacion' => $i->estacion, 'ronda' => $i->ronda, 'enviado' => $i->enviado_en?->format('H:i'), 'listo' => $i->listo_en?->format('H:i')]),
            'pendientes_envio' => $c->items->where('estado', 'pedido')->count(), 'listos' => $c->items->where('estado', 'listo')->count(),
        ];
    }

    public function mesas(Request $request)
    {
        $user = $request->user();
        $mesas = Mesa::with('comandaAbierta.items', 'comandaAbierta.mozo:id,name')->where('business_location_id', $user->current_location_id)->orderBy('sector')->orderBy('orden')->orderBy('nombre')->get();
        $hoy = Comanda::where('estado', 'cerrada')->whereDate('cerrada_en', today());
        return Inertia::render('Gastronomia/Mesas', [
            'mesas' => $mesas->map(fn($m) => ['id' => $m->id, 'nombre' => $m->nombre, 'sector' => $m->sector ?: 'Salón', 'capacidad' => $m->capacidad, 'activa' => $m->activa, 'orden' => $m->orden, 'pos_x' => $m->pos_x !== null ? (float) $m->pos_x : null, 'pos_y' => $m->pos_y !== null ? (float) $m->pos_y : null, 'forma' => $m->forma ?: 'cuadrada',
                'comanda' => $m->comandaAbierta ? ['id' => $m->comandaAbierta->id, 'llamado' => $m->comandaAbierta->llamado, 'llamado_min' => $m->comandaAbierta->llamado_en ? (int) $m->comandaAbierta->llamado_en->diffInMinutes(now()) : null, 'estado' => $m->comandaAbierta->estado, 'total' => (float) $m->comandaAbierta->total, 'minutos' => (int) $m->comandaAbierta->abierta_en->diffInMinutes(now()), 'mozo' => $m->comandaAbierta->mozo?->name, 'cubiertos' => $m->comandaAbierta->cubiertos, 'listos' => $m->comandaAbierta->items->where('estado', 'listo')->count(), 'sin_enviar' => $m->comandaAbierta->items->where('estado', 'pedido')->count()] : null]),
            'otras' => Comanda::abiertas()->whereNull('mesa_id')->with('items', 'mozo:id,name')->orderBy('abierta_en')->get()->map(fn($c) => $this->comandaArr($c)),
            'kpis' => ['abiertas' => Comanda::abiertas()->count(), 'ocupadas' => $mesas->filter(fn($m) => $m->comandaAbierta)->count(), 'mesas' => $mesas->where('activa', true)->count(), 'ventas_hoy' => (float) (clone $hoy)->sum('total'), 'cubiertos_hoy' => (int) (clone $hoy)->sum('cubiertos'), 'comandas_hoy' => (clone $hoy)->count(), 'propinas_hoy' => (float) (clone $hoy)->sum('propina'), 'en_cocina' => ComandaItem::where('estado', 'cocina')->whereHas('comanda', fn($q) => $q->abiertas())->count()],
            'listaSucursales' => $user->business->locations()->where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function guardarMesa(Request $request, ?int $id = null)
    {
        $d = $request->validate(['nombre' => 'required|string|max:30', 'sector' => 'nullable|string|max:40', 'capacidad' => 'nullable|integer|min:1|max:50', 'orden' => 'nullable|integer', 'activa' => 'boolean', 'forma' => 'nullable|in:cuadrada,redonda,larga', 'pos_x' => 'nullable|numeric|min:0|max:100', 'pos_y' => 'nullable|numeric|min:0|max:100']);
        $m = $id ? Mesa::findOrFail($id) : new Mesa(['business_id' => $request->user()->business_id, 'business_location_id' => $request->user()->current_location_id]);
        $m->fill($d)->save();
        return back()->with('success', 'Mesa guardada.');
    }

    // Fase 89: el plano del salón: dónde queda cada mesa dentro de su sector (en % del ancho y del alto).
    public function plano(Request $request)
    {
        $d = $request->validate(['mesas' => 'required|array', 'mesas.*.id' => 'required|integer', 'mesas.*.pos_x' => 'required|numeric|min:0|max:100', 'mesas.*.pos_y' => 'required|numeric|min:0|max:100']);
        $mias = Mesa::whereIn('id', array_column($d['mesas'], 'id'))->get()->keyBy('id');
        foreach ($d['mesas'] as $m) if (isset($mias[$m['id']])) $mias[$m['id']]->update(['pos_x' => round((float) $m['pos_x'], 2), 'pos_y' => round((float) $m['pos_y'], 2)]);
        return back()->with('success', 'Plano guardado.');
    }

    public function eliminarMesa(int $id)
    {
        $m = Mesa::findOrFail($id);
        abort_if($m->comandaAbierta, 422, 'La mesa tiene una comanda abierta.');
        $m->delete();
        return back()->with('success', 'Mesa eliminada.');
    }

    public function abrir(Request $request, ComandaService $svc)
    {
        $d = $request->validate(['mesa_id' => 'nullable|exists:mesas,id', 'tipo' => 'nullable|in:mesa,mostrador,delivery', 'cubiertos' => 'nullable|integer|min:0|max:100', 'cliente' => 'nullable|string|max:120', 'direccion' => 'nullable|string|max:200', 'telefono' => 'nullable|string|max:40']);
        $c = $svc->abrir($d);
        return redirect("/gastronomia/comandas/{$c->id}");
    }

    public function comanda(int $id)
    {
        $c = Comanda::findOrFail($id);
        if ($c->llamado) $c->update(['llamado' => null, 'llamado_en' => null]); // el mozo ya la atendió
        return Inertia::render('Gastronomia/Comanda', [
            'comanda' => $this->comandaArr($c),
            'productos' => Product::where('active', true)->where('tipo', '!=', 'insumo')->with('rubro:id,nombre,color')->orderByDesc('favorito_pos')->orderBy('name')->get()->filter(fn($p) => \App\Services\Gastronomia\HorariosService::disponible($p))->values()->map(fn($p) => ['id' => $p->id, 'hh' => \App\Services\Gastronomia\HorariosService::happyHour(auth()->user()->business, $p)['nombre'] ?? null, 'name' => $p->name, 'price' => \App\Services\Gastronomia\HorariosService::aplicar($this->precioFinal($p), \App\Services\Gastronomia\HorariosService::happyHour(auth()->user()->business, $p)), 'rubro_id' => $p->rubro_id, 'rubro' => $p->rubro?->nombre, 'color' => $p->rubro?->color, 'va_cocina' => $p->va_cocina, 'favorito' => $p->favorito_pos, 'modificadores' => $p->modificadores ?? []]),
            'rubros' => Rubro::orderBy('orden')->orderBy('nombre')->get(['id', 'nombre', 'color']),
            'medios' => Cobro::MEDIOS, 'cuentas' => CuentaFondos::where('activa', true)->orderBy('tipo')->get(['id', 'tipo', 'nombre']),
            'clientes' => Contact::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'cuit', 'condicion_iva']),
            'mesas' => Mesa::where('activa', true)->orderBy('sector')->orderBy('nombre')->get(['id', 'nombre', 'sector']),
        ]);
    }

    public function agregar(Request $request, int $id, ComandaService $svc)
    {
        $d = $request->validate(['product_id' => 'nullable|exists:products,id', 'descripcion' => 'nullable|string|max:150', 'cantidad' => 'nullable|numeric|min:0.001', 'precio_unit' => 'nullable|numeric|min:0', 'asiento' => 'nullable|integer|min:1|max:50', 'notas' => 'nullable|string|max:150', 'modificadores' => 'nullable|array']);
        $svc->agregar(Comanda::findOrFail($id), $d);
        return back();
    }

    // Fase 89: cortesía (invitación de la casa, consumo del personal, error de cocina): el ítem queda a $ 0 y la merma registra el costo.
    public function cortesia(Request $request, int $id, int $item, ComandaService $svc)
    {
        $d = $request->validate(['motivo' => 'required|in:cortesia,consumo_personal,error_cocina', 'notas' => 'nullable|string|max:120']);
        $c = Comanda::findOrFail($id);
        $svc->cortesia($c->items()->findOrFail($item), $d['motivo'], $d['notas'] ?? null);
        return back()->with('success', 'Marcado como cortesía.');
    }

    public function quitar(int $id, int $item, ComandaService $svc)
    {
        $svc->quitar(ComandaItem::where('comanda_id', $id)->findOrFail($item));
        return back();
    }

    public function enviar(int $id, ComandaService $svc)
    {
        $n = $svc->enviarCocina(Comanda::findOrFail($id));
        return back()->with('success', $n ? "{$n} ítem" . ($n > 1 ? 's' : '') . " enviado" . ($n > 1 ? 's' : '') . " a cocina." : 'No había nada pendiente de enviar.');
    }

    public function itemEstado(Request $request, int $item, ComandaService $svc)
    {
        $d = $request->validate(['estado' => 'required|in:pedido,cocina,listo,entregado,anulado']);
        $svc->estadoItem(ComandaItem::findOrFail($item), $d['estado']);
        return back();
    }

    public function cuenta(int $id, ComandaService $svc)
    {
        $svc->pedirCuenta(Comanda::findOrFail($id));
        return back()->with('success', 'Cuenta pedida. Cerrala cuando cobres.');
    }

    public function mover(Request $request, int $id)
    {
        $d = $request->validate(['mesa_id' => 'required|exists:mesas,id']);
        $mesa = Mesa::findOrFail($d['mesa_id']);
        abort_if($mesa->comandaAbierta, 422, 'La mesa destino está ocupada.');
        Comanda::findOrFail($id)->update(['mesa_id' => $mesa->id, 'tipo' => 'mesa']);
        return back()->with('success', "Movida a la mesa {$mesa->nombre}.");
    }

    public function cerrar(Request $request, int $id, ComandaService $svc)
    {
        $d = $request->validate(['contact_id' => 'nullable|exists:contacts,id', 'descuento' => 'nullable|numeric|min:0', 'propina' => 'nullable|numeric|min:0', 'a_cuenta' => 'boolean', 'medios' => 'nullable|array', 'medios.*.medio' => 'required|in:' . implode(',', array_keys(Cobro::MEDIOS)), 'medios.*.monto' => 'required|numeric|min:0', 'medios.*.cuenta_fondos_id' => 'nullable|integer', 'medios.*.referencia' => 'nullable|string|max:120']);
        // Fase 49: cobrar una parte (cada uno lo suyo, o una de N partes iguales); si no queda nada, se cierra igual que siempre.
        $p = $request->validate(['partes' => 'nullable|array', 'partes.*' => 'numeric|min:0', 'dividir' => 'nullable|integer|min:1|max:50']);
        $partes = ! empty($p['dividir']) ? ['_dividir' => (int) $p['dividir']] : (! empty($p['partes']) ? $p['partes'] : null);
        $r = $svc->cobrar(Comanda::findOrFail($id), $d, $partes);
        $c = $r['comprobante'];
        if (! $r['cerrada']) return back()->with('success', "Parte cobrada: {$c->nombreTipo()} {$c->numeroFormateado()} por $ " . number_format((float) $c->total, 2, ',', '.') . '. Falta $ ' . number_format($this->comandaArr($r['comanda']->fresh())['pendiente'], 2, ',', '.') . '.')->with('pos', ['comprobante_id' => $c->id, 'vuelto' => $r['vuelto']]);
        return redirect('/gastronomia')->with('success', "{$r['comanda']->titulo()} cerrada: {$c->nombreTipo()} {$c->numeroFormateado()} por $ " . number_format((float) $c->total, 2, ',', '.') . ($r['vuelto'] > 0 ? ' · vuelto $ ' . number_format($r['vuelto'], 2, ',', '.') : ''))->with('pos', ['comprobante_id' => $c->id, 'vuelto' => $r['vuelto']]);
    }

    public function anular(Request $request, int $id, ComandaService $svc)
    {
        $d = $request->validate(['motivo' => 'required|string|max:200']);
        $svc->anular(Comanda::findOrFail($id), $d['motivo']);
        return redirect('/gastronomia')->with('success', 'Comanda anulada.');
    }

    // Pantalla de cocina: lo pendiente agrupado por comanda, más viejo primero. Se refresca sola.
    public function cocina(Request $request)
    {
        // Fase 50: cada estación ve lo suyo (?estacion=Barra); sin filtro, todo. La alerta de demora es la de cada estación.
        $estaciones = $request->user()->business->estacionesCocina(); $primera = $estaciones[0]['nombre'];
        $est = $request->estacion && collect($estaciones)->contains('nombre', $request->estacion) ? $request->estacion : null;
        $alerta = collect($estaciones)->mapWithKeys(fn($e) => [$e['nombre'] => (int) $e['alerta']]);
        $items = ComandaItem::with('comanda.mesa:id,nombre,sector', 'comanda.mozo:id,name')->whereIn('estado', ['cocina', 'listo'])->where('va_cocina', true)->whereHas('comanda', fn($q) => $q->abiertas())
            ->when($est, fn($q) => $q->where(fn($w) => $w->where('estacion', $est)->when($est === $primera, fn($w) => $w->orWhereNull('estacion'))))->orderBy('enviado_en')->get();
        $grupos = $items->groupBy('comanda_id')->map(fn($its) => ['comanda_id' => $its->first()->comanda_id, 'titulo' => $its->first()->comanda->titulo(), 'mozo' => $its->first()->comanda->mozo?->name, 'enviado' => $its->min('enviado_en')?->format('H:i'), 'minutos' => (int) ($its->min('enviado_en')?->diffInMinutes(now()) ?? 0),
            'items' => $its->map(fn($i) => ['id' => $i->id, 'estacion' => $i->estacion ?? $primera, 'alerta' => $alerta[$i->estacion ?? $primera] ?? 20, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'notas' => $i->notas, 'estado' => $i->estado, 'ronda' => $i->ronda, 'minutos' => (int) ($i->enviado_en?->diffInMinutes(now()) ?? 0)])->values()])->values();
        if ($request->wantsJson() || $request->boolean('json')) return response()->json($grupos);
        // Tiempo promedio de hoy (enviado → listo) por estación.
        $hoy = ComandaItem::whereNotNull('listo_en')->whereNotNull('enviado_en')->where('va_cocina', true)->whereDate('listo_en', today())->get(['estacion', 'enviado_en', 'listo_en'])
            ->groupBy(fn($i) => $i->estacion ?? $primera)->map(fn($g) => (int) round($g->avg(fn($i) => $i->enviado_en->diffInMinutes($i->listo_en))));
        return Inertia::render('Gastronomia/Cocina', ['estaciones' => $estaciones, 'estacion' => $est, 'promedios' => $hoy, 'proximaHora' => app(\App\Services\Gastronomia\PronosticoRestoService::class)->proximaHora($request->user()->business), 'grupos' => $grupos, 'terminados' => ComandaItem::where('estado', 'entregado')->where('va_cocina', true)->whereDate('updated_at', today())->count()]);
    }

    // Fase 49 · Carta: qué va a cocina y los modificadores de cada plato (punto de la carne, guarnición, extras con precio).
    public function carta()
    {
        return Inertia::render('Gastronomia/Carta', [
            'productos' => Product::where('active', true)->where('tipo', '!=', 'insumo')->with('rubro:id,nombre')->orderBy('name')->get()->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'price' => $this->precioFinal($p), 'rubro' => $p->rubro?->nombre, 'va_cocina' => (bool) $p->va_cocina, 'estacion' => $p->estacion, 'horario' => $p->horario, 'modificadores' => $p->modificadores ?? [],
                // Fase 53: costo (de la receta si tiene), margen sobre el precio neto y la receta para editarla acá mismo.
                'precio_neto' => (float) $p->price, 'costo' => \App\Services\Gastronomia\RecetaVentaService::costo($p), 'margen' => \App\Services\Gastronomia\RecetaVentaService::margen($p), 'margen_objetivo' => $p->margen_objetivo !== null ? (float) $p->margen_objetivo : null,
                'receta' => ($r = \App\Services\Gastronomia\RecetaVentaService::receta($p)) ? ['id' => $r->id, 'yield' => (float) $r->yield_quantity, 'items' => $r->items->map(fn($i) => ['product_id' => $i->product_id, 'nombre' => $i->product?->name, 'quantity' => (float) $i->quantity, 'unit' => $i->unit, 'costo' => round((float) $i->quantity * (float) ($i->product?->cost ?? 0), 2)])->values()] : null]),
            'insumos' => Product::where('active', true)->whereIn('tipo', ['insumo', 'producto'])->orderBy('name')->limit(600)->get(['id', 'name', 'unit', 'cost'])->map(fn($p) => ['id' => $p->id, 'nombre' => $p->name, 'unit' => $p->unit, 'cost' => (float) $p->cost]),
            'recetaAlVender' => \App\Services\Gastronomia\RecetaVentaService::activo(auth()->user()->business), 'whatsappAResto' => (bool) (auth()->user()->business->gastronomia_config['whatsapp_a_resto'] ?? true), 'ia' => \App\Support\Ia::tiene(auth()->user()->business),
            'happyHours' => auth()->user()->business->gastronomia_config['happy_hours'] ?? [], 'rubros' => \App\Models\Rubro::orderBy('nombre')->get(['id', 'nombre']),
            'estaciones' => auth()->user()->business->estacionesCocina(), 'qrConfirma' => (bool) (auth()->user()->business->gastronomia_config['qr_confirma'] ?? false),
        ]);
    }

    public function guardarCarta(Request $request, int $id)
    {
        $d = $request->validate(['margen_objetivo' => 'nullable|numeric|min:0|max:99', 'horario' => 'nullable|array', 'horario.dias' => 'nullable|array', 'horario.dias.*' => 'integer|between:1,7', 'horario.desde' => 'nullable|date_format:H:i', 'horario.hasta' => 'nullable|date_format:H:i', 'va_cocina' => 'boolean', 'estacion' => 'nullable|string|max:30', 'modificadores' => 'nullable|array|max:10', 'modificadores.*.nombre' => 'required|string|max:40', 'modificadores.*.obligatorio' => 'boolean', 'modificadores.*.multiple' => 'boolean',
            'modificadores.*.opciones' => 'required|array|min:1|max:30', 'modificadores.*.opciones.*.nombre' => 'required|string|max:40', 'modificadores.*.opciones.*.precio' => 'nullable|numeric|min:0']);
        $p = Product::findOrFail($id);
        $mods = collect($d['modificadores'] ?? [])->map(fn($g) => ['nombre' => trim($g['nombre']), 'obligatorio' => (bool) ($g['obligatorio'] ?? false), 'multiple' => (bool) ($g['multiple'] ?? false), 'opciones' => collect($g['opciones'])->map(fn($o) => ['nombre' => trim($o['nombre']), 'precio' => round((float) ($o['precio'] ?? 0), 2)])->values()->all()])->values()->all();
        $p->forceFill(['modificadores' => $mods ?: null] + (array_key_exists('margen_objetivo', $d) ? ['margen_objetivo' => $d['margen_objetivo']] : []) + (array_key_exists('va_cocina', $d) ? ['va_cocina' => $d['va_cocina']] : []) + (array_key_exists('estacion', $d) ? ['estacion' => $d['estacion'] ?: null] : []) + (array_key_exists('horario', $d) ? ['horario' => ! empty($d['horario']['desde']) && ! empty($d['horario']['hasta']) ? ['dias' => array_values(array_map('intval', $d['horario']['dias'] ?? [])), 'desde' => $d['horario']['desde'], 'hasta' => $d['horario']['hasta']] : null] : []))->save();
        return back()->with('success', "{$p->name}: carta guardada.");
    }

    // Fase 50: las estaciones y cuántos minutos espera cada una antes de marcar el plato en rojo.
    public function guardarEstaciones(Request $request)
    {
        $d = $request->validate(['whatsapp_a_resto' => 'boolean', 'receta_al_vender' => 'boolean', 'qr_confirma' => 'boolean', 'estaciones' => 'required|array|min:1|max:8', 'estaciones.*.nombre' => 'required|string|max:30|distinct', 'estaciones.*.alerta' => 'required|integer|min:1|max:180']);
        $b = $request->user()->business; $cfg = $b->gastronomia_config ?? [];
        if (array_key_exists('qr_confirma', $d)) $cfg['qr_confirma'] = (bool) $d['qr_confirma'];
        if (array_key_exists('receta_al_vender', $d)) $cfg['receta_al_vender'] = (bool) $d['receta_al_vender'];
        if (array_key_exists('whatsapp_a_resto', $d)) $cfg['whatsapp_a_resto'] = (bool) $d['whatsapp_a_resto'];
        $cfg['estaciones'] = collect($d['estaciones'])->map(fn($e) => ['nombre' => trim($e['nombre']), 'alerta' => (int) $e['alerta']])->values()->all();
        $b->forceFill(['gastronomia_config' => $cfg])->save();
        return back()->with('success', 'Estaciones guardadas.');
    }

    // Comanda impresa (ticket de 80 mm) de una estación: lo enviado de esa ronda o todo lo pendiente.
    public function imprimir(Request $request, int $id)
    {
        $c = Comanda::with('mesa', 'mozo:id,name')->findOrFail($id);
        $primera = $request->user()->business->estacionesCocina()[0]['nombre'];
        $items = $c->items()->where('va_cocina', true)->whereIn('estado', ['cocina', 'listo'])
            ->when($request->estacion, fn($q, $e) => $q->where(fn($w) => $w->where('estacion', $e)->when($e === $primera, fn($w) => $w->orWhereNull('estacion'))))
            ->when($request->ronda, fn($q, $r) => $q->where('ronda', (int) $r))->orderBy('id')->get();
        return response()->view('gastronomia.ticket-cocina', ['c' => $c, 'items' => $items, 'estacion' => $request->estacion ?: 'Cocina']);
    }

    // Fase 52: happy hour — descuento por franja horaria, días y rubros.
    public function guardarHappyHours(Request $request)
    {
        $d = $request->validate(['happy_hours' => 'present|array|max:10', 'happy_hours.*.nombre' => 'required|string|max:40', 'happy_hours.*.activo' => 'boolean', 'happy_hours.*.pct' => 'required|numeric|min:1|max:90',
            'happy_hours.*.desde' => 'required|date_format:H:i', 'happy_hours.*.hasta' => 'required|date_format:H:i', 'happy_hours.*.dias' => 'nullable|array', 'happy_hours.*.dias.*' => 'integer|between:1,7', 'happy_hours.*.rubros' => 'nullable|array', 'happy_hours.*.rubros.*' => 'integer']);
        $b = $request->user()->business; $cfg = $b->gastronomia_config ?? [];
        $cfg['happy_hours'] = collect($d['happy_hours'])->map(fn($r) => ['nombre' => trim($r['nombre']), 'activo' => (bool) ($r['activo'] ?? true), 'pct' => (float) $r['pct'], 'desde' => $r['desde'], 'hasta' => $r['hasta'], 'dias' => array_values(array_map('intval', $r['dias'] ?? [])), 'rubros' => array_values(array_map('intval', $r['rubros'] ?? []))])->values()->all();
        $b->forceFill(['gastronomia_config' => $cfg])->save();
        return back()->with('success', 'Happy hour guardado.');
    }

    // Fase 53: la receta del plato desde la Carta (lo que descuenta cada venta). Guarda con la misma lógica de Producción → Fórmulas.
    public function guardarReceta(Request $request, int $id, \App\Services\Produccion\ProduccionService $prod)
    {
        $d = $request->validate(['items' => 'present|array|max:40', 'items.*.product_id' => 'required|integer', 'items.*.quantity' => 'required|numeric|min:0', 'items.*.unit' => 'nullable|string|max:10']);
        $p = Product::findOrFail($id);
        $r = \App\Services\Gastronomia\RecetaVentaService::receta($p);
        $items = collect($d['items'])->filter(fn($i) => (float) $i['quantity'] > 0)->values()->all();
        if (! $items) { if ($r) { $r->items()->delete(); $r->delete(); } return back()->with('success', "{$p->name}: receta quitada."); }
        $prod->guardarFormula(['product_id' => $p->id, 'name' => $p->name, 'yield_quantity' => 1, 'yield_unit' => $p->unit ?: 'un', 'items' => $items], $r);
        return back()->with('success', "{$p->name}: receta guardada. Costo $ " . number_format(\App\Services\Gastronomia\RecetaVentaService::costo($p->fresh()), 2, ',', '.') . '.');
    }

    public function sugerirReceta(Request $request, int $id, \App\Services\Gastronomia\RecetaIaService $ia)
    {
        return response()->json($ia->sugerir($request->user()->business, Product::findOrFail($id)));
    }

    // Fase 53 · Llamador: los pedidos de mostrador que se están preparando y los que están listos para retirar. Pantalla para la tele del local.
    public function llamador(Request $request)
    {
        $cs = Comanda::abiertas()->whereIn('tipo', ['mostrador', 'delivery'])->with('items')->orderBy('abierta_en')->get()->map(function ($c) {
            $its = $c->items->where('estado', '!=', 'anulado'); $listo = $its->isNotEmpty() && $its->every(fn($i) => in_array($i->estado, ['listo', 'entregado'], true));
            return ['id' => $c->id, 'numero' => $c->numeroFormateado(), 'corto' => (string) ((int) $c->numero % 1000), 'cliente' => $c->cliente, 'tipo' => $c->tipo, 'listo' => $listo, 'avisado' => (bool) $c->aviso_listo_en, 'telefono' => (bool) $c->telefono, 'minutos' => (int) $c->abierta_en->diffInMinutes(now())];
        });
        if ($request->wantsJson()) return response()->json($cs);
        return Inertia::render('Gastronomia/Llamador', ['pedidos' => $cs]);
    }

    // Marcar un pedido de mostrador como entregado (lo retiró el cliente): queda listo para cobrar o ya cobrado.
    public function retirado(int $id)
    {
        $c = Comanda::findOrFail($id);
        $c->items()->whereIn('estado', ['listo'])->update(['estado' => 'entregado']);
        return back();
    }

    // Fase 53.2: la carta desde una foto, la pizarra o un PDF. Primero se lee (vista previa editable), después se carga.
    public function leerCarta(Request $request, \App\Services\Gastronomia\CartaIaService $ia)
    {
        $request->validate(['archivo' => 'required|file|max:12288|mimes:jpg,jpeg,png,webp,pdf']);
        $f = $request->file('archivo'); $mime = $f->getMimeType() === 'application/pdf' ? 'application/pdf' : ($f->getMimeType() ?: 'image/jpeg');
        return response()->json($ia->leer(file_get_contents($f->getRealPath()), $mime));
    }

    public function importarCarta(Request $request, \App\Services\Gastronomia\CartaIaService $ia)
    {
        $d = $request->validate(['rubros' => 'required|array|min:1', 'rubros.*.nombre' => 'required|string|max:60', 'rubros.*.platos' => 'required|array', 'rubros.*.platos.*.nombre' => 'required|string|max:120', 'rubros.*.platos.*.precio' => 'nullable|numeric|min:0', 'rubros.*.platos.*.descripcion' => 'nullable|string|max:300', 'rubros.*.platos.*.existe_id' => 'nullable|integer', 'rubros.*.platos.*.cargar' => 'boolean', 'rubros.*.platos.*.opciones' => 'nullable|array']);
        $r = $ia->importar($request->user()->business, $d['rubros'], $request->user()->current_location_id);
        return back()->with('success', "Carta cargada: {$r['nuevos']} platos nuevos" . ($r['actualizados'] ? ", {$r['actualizados']} con precio actualizado" : '') . '. Revisá las recetas y qué va a cocina.');
    }

    // Fase 55: el menú del día sugerido con lo que está por vencer o sobra.
    public function menuDelDia(Request $request, \App\Services\Gastronomia\MenuDelDiaIaService $ia)
    {
        return response()->json($ia->sugerir($request->user()->business));
    }

    // Fase 56: la carta traducida para el QR (inglés, portugués).
    public function traducir(Request $request, \App\Services\Gastronomia\CartaQrService $q)
    {
        $d = $request->validate(['idioma' => 'required|in:' . implode(',', array_keys(\App\Services\Gastronomia\CartaQrService::IDIOMAS))]);
        $r = $q->traducir($request->user()->business, $d['idioma']);
        return back()->with($r['ok'] ? 'success' : 'error', $r['aviso']);
    }
}
