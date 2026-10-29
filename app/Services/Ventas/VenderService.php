<?php

namespace App\Services\Ventas;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Contact;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendedor;
use App\Models\Visita;
use App\Services\Comprobantes\ComprobanteService;
use App\Services\Pos\PosService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Fase 29: vender en la calle. La ruta del día (agenda del cliente o su ritmo real de compra), lo que suele llevar, el pedido
// sugerido, el control de crédito y la venta que entra como presupuesto, factura o remito con la ubicación sellada.
class VenderService
{
    public const MODOS = ['calle' => 'En la calle', 'mostrador' => 'En el local', 'caja' => 'En la caja'];
    public const DIAS = ['lun', 'mar', 'mie', 'jue', 'vie', 'sab', 'dom'];
    public const AGENDA = ['dia' => 'Todo el día', 'man' => 'A la mañana', 'tar' => 'A la tarde', '1er' => '1ª semana', '2do' => '2ª semana', '3er' => '3ª semana', 'ult' => 'Última semana'];
    public const DEFAULT = ['calle_comprobante' => 'PRE', 'calle_emite' => false, 'mostrador_emite' => true, 'tope_descuento' => 0, 'dias_mora' => 0, 'avisar_datos_dias' => 7, 'calle_cobra' => true];

    public function __construct(private ComprobanteService $comprobantes, private PosService $pos, private CondicionesClienteService $condiciones) {}

    // Se lee de la base y no de la relación cacheada: el encargado puede cambiarla mientras el vendedor está en la calle.
    public function config(?Business $b): array { return array_replace(self::DEFAULT, ($b ? Business::whereKey($b->id)->value('ventas_config') : null) ?? []); }

    // ---- Ruta del día ----

    // Los clientes del vendedor agrupados: hoy por agenda, le toca, todavía no, dejó de comprar, sin historial, casi diarios.
    public function ruta(User $u, ?Vendedor $v, ?string $buscar = null): array
    {
        $like = \App\Support\Sql::like();
        $q = Contact::customers()->where('is_active', true)->where('name', '!=', 'Consumidor Final')
            ->when($v, fn($q) => $q->where(fn($w) => $w->where('vendedor_id', $v->id)->orWhereNull('vendedor_id')))
            ->when($buscar, fn($q) => $q->where(fn($w) => $w->where('name', $like, "%{$buscar}%")->orWhere('cuit', $like, "%{$buscar}%")->orWhere('city', $like, "%{$buscar}%")))
            ->orderBy('name')->limit(600);
        $clientes = $q->get();
        if ($clientes->isEmpty()) return ['grupos' => [], 'total' => 0];
        $ritmos = $this->ritmos($clientes->pluck('id'));
        $hoy = today(); $diaHoy = self::DIAS[$hoy->dayOfWeekIso - 1]; $semana = (int) ceil($hoy->day / 7); $ultima = $hoy->day > $hoy->daysInMonth - 7;
        $grupos = ['agenda' => [], 'toca' => [], 'no_toca' => [], 'dejo' => [], 'sin_historial' => [], 'diarios' => []];
        foreach ($clientes as $c) {
            $r = $ritmos[$c->id] ?? null;
            $fila = $this->filaRuta($c, $r);
            $ag = $c->visitas[$diaHoy] ?? null;
            if ($ag && $ag !== 'no' && (in_array($ag, ['dia', 'man', 'tar'], true) || ($ag === '1er' && $semana === 1) || ($ag === '2do' && $semana === 2) || ($ag === '3er' && $semana === 3) || ($ag === 'ult' && $ultima))) {
                $fila['agenda'] = self::AGENDA[$ag] ?? $ag; $grupos['agenda'][] = $fila; continue;
            }
            if (! $r) { $grupos['sin_historial'][] = $fila; continue; }
            if ($r['ritmo'] <= 2) { $grupos['diarios'][] = $fila; continue; }
            $atraso = $r['dias'] - $r['ritmo'];
            if ($r['dias'] > $r['ritmo'] * 3) $grupos['dejo'][] = $fila;
            elseif ($atraso >= 0) $grupos['toca'][] = $fila;
            else $grupos['no_toca'][] = $fila;
        }
        usort($grupos['toca'], fn($a, $b) => $b['dias'] <=> $a['dias']);
        usort($grupos['no_toca'], fn($a, $b) => ($b['dias'] - $b['ritmo']) <=> ($a['dias'] - $a['ritmo']));
        return ['grupos' => array_filter($grupos), 'total' => $clientes->count(), 'hasta' => Comprobante::ventas()->emitidos()->max('fecha')];
    }

    private function filaRuta(Contact $c, ?array $r): array
    {
        $vencido = (float) Comprobante::where('contact_id', $c->id)->pendientesCobro()->where('fecha_vto', '<', today()->toDateString())->sum('saldo');
        $masVieja = $vencido > 0 ? Comprobante::where('contact_id', $c->id)->pendientesCobro()->where('fecha_vto', '<', today()->toDateString())->min('fecha_vto') : null;
        return ['id' => $c->id, 'nombre' => $c->name, 'cuit' => $c->cuit, 'direccion' => trim(($c->address ?? '') . ' ' . ($c->city ?? '')), 'telefono' => $c->mobile ?: $c->phone, 'email' => $c->email,
            'saldo' => (float) $c->balance, 'limite' => (float) $c->credit_limit, 'vencido' => round($vencido, 2), 'mora_dias' => $masVieja ? Carbon::parse($masVieja)->diffInDays(today()) : 0,
            'lista' => (int) ($c->lista_precios ?: 1), 'descuento' => (float) $c->descuento, 'condicion_iva' => $c->condicion_iva, 'lat' => $c->lat, 'lng' => $c->lng, 'notas' => $c->notes,
            'ritmo' => $r['ritmo'] ?? null, 'dias' => $r['dias'] ?? null, 'ultima' => $r['ultima'] ?? null];
    }

    // Ritmo de compra: mediana de los días entre las últimas 8 facturas, y hace cuántos días fue la última. Medido contra la
    // última venta del sistema, no contra hoy, para que un sistema que no se usó una semana no envejezca a todos los clientes.
    private function ritmos(Collection $ids): array
    {
        $hasta = Comprobante::ventas()->emitidos()->max('fecha'); $hasta = $hasta ? Carbon::parse($hasta) : today();
        $fechas = Comprobante::ventas()->emitidos()->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'REM'])->whereIn('contact_id', $ids)->where('fecha', '>=', today()->subMonths(18)->toDateString())
            ->orderByDesc('fecha')->get(['contact_id', 'fecha'])->groupBy('contact_id');
        $out = [];
        foreach ($fechas as $cid => $fs) {
            $dias = $fs->pluck('fecha')->map(fn($f) => $f->toDateString())->unique()->values()->take(8)->map(fn($f) => Carbon::parse($f));
            $ultima = $dias->first();
            if ($dias->count() < 2) { $out[$cid] = ['ritmo' => null, 'dias' => $ultima->diffInDays($hasta), 'ultima' => $ultima->toDateString()]; unset($out[$cid]); continue; }
            $gaps = []; for ($i = 0; $i < $dias->count() - 1; $i++) $gaps[] = $dias[$i + 1]->diffInDays($dias[$i]);
            sort($gaps); $m = count($gaps); $ritmo = $m % 2 ? $gaps[intdiv($m, 2)] : ($gaps[$m / 2 - 1] + $gaps[$m / 2]) / 2;
            $out[$cid] = ['ritmo' => max(1, (int) round($ritmo)), 'dias' => $ultima->diffInDays($hasta), 'ultima' => $ultima->toDateString()];
        }
        return $out;
    }

    // ---- La ficha del cliente para vender: condiciones, lo que suele llevar, el último pedido y el sugerido ----
    public function ficha(Contact $c): array
    {
        $cond = $this->condiciones->paraCliente($c);
        $hist = $this->historial($c);
        $ultimo = Comprobante::where('contact_id', $c->id)->ventas()->emitidos()->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'REM', 'PRE'])->orderByDesc('fecha')->orderByDesc('id')->with('items')->first();
        return [
            'cliente' => $this->filaRuta($c, $this->ritmos(collect([$c->id]))[$c->id] ?? null),
            'condiciones' => ['lista' => $cond['lista'], 'descuento' => $cond['descuento'], 'rubros' => $cond['rubros'], 'articulos' => $cond['articulos']],
            'suele_llevar' => $hist['top'],
            'ultimo' => $ultimo ? ['fecha' => $ultimo->fecha->toDateString(), 'numero' => $ultimo->numeroFormateado(), 'items' => $ultimo->items->map(fn($i) => ['product_id' => $i->product_id, 'cantidad' => (float) $i->cantidad])->filter(fn($i) => $i['product_id'])->values()->all()] : null,
            'sugerido' => $hist['sugerido'], 'hace_rato' => $hist['hace_rato'],
            'visitas' => Visita::where('contact_id', $c->id)->orderByDesc('created_at')->limit(5)->get()->map(fn($v) => ['tipo' => $v->tipo, 'motivo' => $v->motivo ? Visita::MOTIVOS[$v->motivo] ?? $v->motivo : null, 'cuando' => $v->created_at->format('d/m H:i'), 'nota' => $v->nota])->all(),
            'pendientes' => Comprobante::where('contact_id', $c->id)->pendientesCobro()->orderBy('fecha_vto')->limit(20)->get()->map(fn($x) => ['id' => $x->id, 'numero' => $x->numeroFormateado(), 'fecha' => $x->fecha->toDateString(), 'vence' => $x->fecha_vto?->toDateString(), 'saldo' => (float) $x->saldo, 'vencida' => $x->fecha_vto && $x->fecha_vto->lt(today())])->all(),
        ];
    }

    // Lo que suele llevar (por cantidad, último año), el pedido sugerido (lo que está en 3 de sus últimas 6 compras, con la
    // cantidad que más repite) y lo que compraba seguido y dejó de llevar.
    private function historial(Contact $c): array
    {
        $comps = Comprobante::where('contact_id', $c->id)->ventas()->emitidos()->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'REM'])->where('fecha', '>=', today()->subYear()->toDateString())->orderByDesc('fecha')->orderByDesc('id')->pluck('id');
        if ($comps->isEmpty()) return ['top' => [], 'sugerido' => [], 'hace_rato' => []];
        $items = ComprobanteItem::whereIn('comprobante_id', $comps)->whereNotNull('product_id')->get(['comprobante_id', 'product_id', 'cantidad']);
        $top = $items->groupBy('product_id')->map(fn($g) => ['product_id' => $g->first()->product_id, 'cantidad' => (float) $g->sum('cantidad'), 'veces' => $g->pluck('comprobante_id')->unique()->count()])->sortByDesc('cantidad')->values()->take(40)->all();
        $ultimas6 = $comps->take(6); $anteriores = $comps->slice(6);
        $enUltimas = $items->whereIn('comprobante_id', $ultimas6)->groupBy('product_id');
        $sugerido = [];
        foreach ($enUltimas as $pid => $g) {
            $veces = $g->pluck('comprobante_id')->unique()->count();
            if ($veces < 3 && $ultimas6->count() >= 3) continue;
            if ($ultimas6->count() < 3 && $veces < 2) continue;
            $cants = $g->pluck('cantidad')->map(fn($x) => (float) $x)->countBy()->sortDesc();
            $ultimaVez = Comprobante::whereKey($g->max('comprobante_id'))->value('fecha');
            $sugerido[] = ['product_id' => (int) $pid, 'cantidad' => (float) $cants->keys()->first(), 'veces' => $veces, 'de' => $ultimas6->count(), 'ultima' => $ultimaVez ? Carbon::parse($ultimaVez)->toDateString() : null];
        }
        usort($sugerido, fn($a, $b) => $b['veces'] <=> $a['veces']);
        $haceRato = [];
        if ($anteriores->isNotEmpty()) {
            $antes = $items->whereIn('comprobante_id', $anteriores)->groupBy('product_id');
            foreach ($antes as $pid => $g) {
                if (isset($enUltimas[$pid])) continue;
                if ($g->pluck('comprobante_id')->unique()->count() < 2) continue;
                $haceRato[] = ['product_id' => (int) $pid, 'cantidad' => (float) $g->avg('cantidad'), 'veces' => $g->pluck('comprobante_id')->unique()->count()];
            }
            usort($haceRato, fn($a, $b) => $b['veces'] <=> $a['veces']);
        }
        return ['top' => $top, 'sugerido' => array_slice($sugerido, 0, 40), 'hace_rato' => array_slice($haceRato, 0, 20)];
    }

    // ---- Visitas ----
    public function visita(User $u, Contact $c, array $d): Visita
    {
        $v = Visita::create(['business_id' => $u->business_id, 'contact_id' => $c->id, 'vendedor_id' => Vendedor::deUsuario($u->id)?->id, 'user_id' => $u->id, 'tipo' => $d['tipo'], 'motivo' => $d['motivo'] ?? null, 'nota' => $d['nota'] ?? null, 'lat' => $d['lat'] ?? null, 'lng' => $d['lng'] ?? null, 'comprobante_id' => $d['comprobante_id'] ?? null, 'created_at' => $d['cuando'] ?? now()]);
        $this->aprenderUbicacion($c, $d['lat'] ?? null, $d['lng'] ?? null);
        return $v;
    }

    // El sistema aprende dónde queda cada cliente con las propias visitas: la primera posición es la referencia y se afina con las siguientes.
    private function aprenderUbicacion(Contact $c, $lat, $lng): void
    {
        if (! $lat || ! $lng) return;
        if ($c->lat && $c->lng) { $c->forceFill(['lat' => round(($c->lat * 3 + $lat) / 4, 7), 'lng' => round(($c->lng * 3 + $lng) / 4, 7)])->saveQuietly(); return; }
        $c->forceFill(['lat' => $lat, 'lng' => $lng])->saveQuietly();
    }

    // ---- Vender ----
    // Mostrador y caja: venta de contado por el circuito del POS. Calle: presupuesto, factura o remito a nombre del cliente,
    // con control de crédito y mora, y la ubicación sellada en el comprobante y en la visita.
    public function vender(User $u, array $d): array
    {
        $modo = $d['modo'] ?? 'mostrador';
        if ($modo !== 'calle') {
            $r = $this->pos->vender($d);
            $r['comprobante']->forceFill(['modo_venta' => $modo])->saveQuietly();
            return $r + ['presupuesto' => false];
        }
        return DB::transaction(function () use ($u, $d) {
            $cfg = $this->config($u->business);
            $contact = Contact::customers()->findOrFail($d['contact_id'] ?? 0);
            $tipo = in_array($d['tipo'] ?? '', ['PRE', 'FX', 'REM'], true) ? $d['tipo'] : $cfg['calle_comprobante'];
            abort_unless($u->puede('comprobantes', 'crear'), 403, 'No tenés permiso para cargar comprobantes.');
            $items = collect($d['items'])->filter(fn($i) => (float) $i['cantidad'] > 0)->values();
            if ($items->isEmpty()) throw ValidationException::withMessages(['items' => 'El pedido está vacío.']);
            $cond = $this->condiciones->paraCliente($contact);
            $lineas = $items->map(function ($i) use ($contact, $cond, $cfg, $u) {
                $p = ! empty($i['product_id']) ? Product::find($i['product_id']) : null;
                // El precio lo pone el servidor con las condiciones del cliente; uno distinto sólo si el usuario puede modificar precios.
                $pc = $p ? $this->condiciones->para($contact, $p, $cond) : null;
                $precio = $pc ? $pc['precio'] : (float) $i['precio_unit'];
                if ($p && abs((float) $i['precio_unit'] - $precio) > 0.005) { abort_unless($u->puede('comprobantes', 'editar'), 403, 'No tenés permiso para cambiar precios.'); $precio = (float) $i['precio_unit']; }
                $desc = (float) ($i['descuento'] ?? ($pc['descuento'] ?? 0));
                if ($pc && $desc > ($pc['descuento'] ?? 0) + 0.005) {
                    abort_unless($u->puede('comprobantes', 'editar'), 403, 'No tenés permiso para hacer descuentos.');
                    if ((float) $cfg['tope_descuento'] > 0 && $desc > (float) $cfg['tope_descuento'] && empty($i['autorizado'])) throw ValidationException::withMessages(['descuento' => "Más de {$cfg['tope_descuento']}% de descuento necesita la autorización del encargado."]);
                }
                return ['product_id' => $p?->id, 'descripcion' => $i['descripcion'] ?? $p?->name, 'cantidad' => $i['cantidad'], 'precio_unit' => $precio, 'descuento' => $desc, 'alicuota_iva' => $p?->iva ?? ($i['alicuota_iva'] ?? 21), 'serie' => $i['serie'] ?? null];
            })->all();
            // Control de crédito y mora: se puede vender igual, pero queda registrado quién y por qué.
            $aviso = $this->controlCredito($contact, $lineas, $cfg);
            if ($aviso && $tipo !== 'PRE' && empty($d['forzar'])) throw ValidationException::withMessages(['credito' => $aviso]);
            $c = $this->comprobantes->guardarBorrador(['contact_id' => $contact->id, 'tipo' => $tipo, 'fecha' => today()->toDateString(), 'condicion' => $tipo === 'PRE' ? 'contado' : 'cta_cte', 'notas' => $d['notas'] ?? null, 'items' => $lineas, 'vendedor_id' => Vendedor::deUsuario($u->id)?->id]);
            $c->forceFill(['modo_venta' => 'calle', 'lat' => $d['lat'] ?? null, 'lng' => $d['lng'] ?? null, 'offline_id' => $d['offline_id'] ?? null])->save();
            $emitida = false;
            if ($tipo !== 'PRE' && $cfg['calle_emite']) { $c = $this->comprobantes->emitir($c); $emitida = true; }
            if ($aviso && ! empty($d['forzar'])) AuditLog::registrar('venta_forzada', $c, "{$u->name} vendió igual a {$contact->name}: {$aviso}");
            $this->visita($u, $contact, ['tipo' => 'venta', 'lat' => $d['lat'] ?? null, 'lng' => $d['lng'] ?? null, 'comprobante_id' => $c->id, 'cuando' => ! empty($d['fecha_offline']) ? Carbon::parse($d['fecha_offline']) : now()]);
            AuditLog::registrar('venta_calle', $c, "{$c->nombreTipo()} " . ($emitida ? $c->numeroFormateado() : "#{$c->id} (" . ($tipo === 'PRE' ? 'presupuesto' : 'pendiente de emitir') . ')') . " a {$contact->name} desde la calle");
            return ['comprobante' => $c->fresh(), 'cobro' => null, 'vuelto' => 0, 'presupuesto' => $tipo === 'PRE', 'emitida' => $emitida, 'aviso' => $aviso];
        });
    }

    // Texto del aviso si con este pedido el cliente se pasa del límite o tiene deuda vencida más allá de la tolerancia; null si está bien.
    public function controlCredito(Contact $c, array $lineas, ?array $cfg = null): ?string
    {
        $cfg ??= $this->config($c->business ?? Auth::user()->business);
        $total = 0; foreach ($lineas as $l) { $calc = ComprobanteItem::calcular((float) $l['cantidad'], (float) $l['precio_unit'], (float) ($l['descuento'] ?? 0), (float) ($l['alicuota_iva'] ?? 21)); $total += (float) $calc['total']; }
        $avisos = [];
        if ((float) $c->credit_limit > 0 && (float) $c->balance + $total > (float) $c->credit_limit) $avisos[] = 'con este pedido se pasa del límite de crédito ($ ' . number_format((float) $c->credit_limit, 0, ',', '.') . ')';
        $vencido = Comprobante::where('contact_id', $c->id)->pendientesCobro()->where('fecha_vto', '<', today()->subDays((int) $cfg['dias_mora'])->toDateString())->sum('saldo');
        if ((float) $vencido > 0.005) $avisos[] = 'tiene $ ' . number_format((float) $vencido, 0, ',', '.') . ' vencidos';
        return $avisos ? ucfirst(implode(' y ', $avisos)) . '.' : null;
    }

    // ---- El paquete para trabajar sin señal: clientes del vendedor y catálogo con las seis listas y las promos ----
    public function paquete(User $u, ?Vendedor $v): array
    {
        $ri = ($u->business->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        $productos = Product::where('active', true)->where('tipo', '!=', 'insumo')->with('rubro:id,nombre,color')->orderBy('name')->limit(6000)->get()
            ->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode, 'unit' => $p->unit, 'iva' => (float) $p->iva, 'stock' => (float) $p->stock, 'controla' => $p->controla_stock, 'rubro_id' => $p->rubro_id, 'rubro' => $p->rubro?->nombre, 'color' => $p->rubro?->color, 'favorito' => $p->favorito_pos, 'seriado' => (bool) $p->seriado, 'plu' => $p->plu,
                'price' => (float) $p->precioLista(1), 'prices' => collect([1, 2, 3, 4, 5, 6])->mapWithKeys(fn($l) => [$l => (float) $p->precioLista($l)])->all(), 'promo' => $p->desc_cant_min > 0 && $p->desc_cant_pct > 0 ? ['desde' => (float) $p->desc_cant_min, 'pct' => (float) $p->desc_cant_pct] : null, 'imagen' => $p->imagen])->values();
        return ['generado' => now()->toIso8601String(), 'ri' => $ri, 'productos' => $productos, 'ruta' => $this->ruta($u, $v), 'config' => $this->config($u->business), 'motivos' => Visita::MOTIVOS];
    }
}
