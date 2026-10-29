<?php

namespace App\Services\Stock;

use App\Models\Business;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CotizacionConsultor;
use App\Models\Product;
use App\Models\ProveedorArticulo;
use App\Models\User;
use App\Services\Ventas\CondicionesClienteService;
use App\Support\Balanza;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Fase 32.4–32.5: el Consultor de precios. Para empleados: buscar, stock, las seis listas, el precio para un cliente, el simulador y la
// cotización por WhatsApp. Para el comprador: costo, margen y a qué proveedores comprar. Para clientes: la pantalla del salón.
class ConsultorService
{
    public function __construct(private CondicionesClienteService $cond) {}

    // Buscar como te acordás del producto: nombre (palabras en cualquier orden), código, código de barras, etiqueta de balanza, y «3*código».
    public function buscar(string $q, User $u, int $lista = 1, ?Contact $cliente = null, int $limite = 30): array
    {
        $q = trim($q); $cantidad = 1;
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*\*\s*(.+)$/', $q, $m)) { $cantidad = (float) str_replace(',', '.', $m[1]); $q = trim($m[2]); }
        if ($q === '') return ['items' => [], 'cantidad' => $cantidad, 'exacto' => false, 'balanza' => null];
        $b = $u->business;
        if (($bz = Balanza::leer($q, $b, $lista)) && $bz['product']) {
            return ['items' => [$this->fila($bz['product'], $u, $lista, $cliente)], 'cantidad' => $bz['cantidad'], 'exacto' => true, 'balanza' => ['cantidad' => $bz['cantidad'], 'importe' => $bz['importe']]];
        }
        // Un código exacto siempre aparece primero, aunque esté discontinuado.
        $exactos = Product::where(fn($w) => $w->where('barcode', $q)->orWhere('sku', $q))->get();
        $like = \App\Support\Sql::like();
        $tokens = array_values(array_filter(preg_split('/\s+/', mb_strtolower($q)), fn($t) => $t !== ''));
        $resto = Product::where('active', true)->whereNotIn('id', $exactos->pluck('id'))
            ->where(function ($w) use ($tokens, $like, $q) {
                foreach ($tokens as $t) $w->where(fn($x) => $x->where('name', $like, "%{$t}%")->orWhere('description', $like, "%{$t}%")->orWhere('sku', $like, "%{$t}%")->orWhere('barcode', $like, "%{$t}%")->orWhereHas('rubro', fn($r) => $r->where('nombre', $like, "%{$t}%")));
            })->orderBy('name')->limit($limite)->get();
        $items = $exactos->concat($resto)->map(fn($p) => $this->fila($p, $u, $lista, $cliente))->values()->all();
        return ['items' => $items, 'cantidad' => $cantidad, 'exacto' => $exactos->isNotEmpty(), 'balanza' => null, 'sin_codigo' => $exactos->isEmpty() && preg_match('/^\d{6,}$/', $q) ? "No hay ningún artículo con el código {$q}. Lo de abajo sólo tiene esos números adentro del código." : null];
    }

    public function fila(Product $p, User $u, int $lista = 1, ?Contact $cliente = null): array
    {
        $ri = ($u->business->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        $final = fn(float $neto) => round($neto * (1 + (float) $p->iva / 100), 2);
        $precios = collect([1, 2, 3, 4, 5, 6])->mapWithKeys(fn($l) => [$l => $final($p->precioLista($l))])->all();
        $out = ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode, 'unit' => $p->unit, 'imagen' => $p->imagen, 'rubro' => $p->rubro?->nombre,
            'stock' => (float) $p->stock, 'controla' => (bool) $p->controla_stock, 'pesable' => (bool) $p->pesable, 'iva' => (float) $p->iva,
            'precio' => $precios[$lista] ?? $precios[1], 'precios' => $precios, 'discontinuado' => ! $p->active,
            'promo' => (float) $p->desc_cant_pct > 0 && (float) $p->desc_cant_min > 0 ? ['min' => (float) $p->desc_cant_min, 'pct' => (float) $p->desc_cant_pct] : null,
            'promo2' => (float) $p->desc_cant2_pct > 0 && (float) $p->desc_cant2_min > 0 ? ['min' => (float) $p->desc_cant2_min, 'pct' => (float) $p->desc_cant2_pct] : null];
        if ($cliente) {
            $pc = $this->cond->para($cliente, $p);
            $out['cliente'] = ['precio' => $final((float) $pc['precio'] * (1 - (float) $pc['descuento'] / 100)), 'descuento' => (float) $pc['descuento'], 'origen' => $pc['origen']];
        }
        if ($u->veCostos()) {
            $costo = (float) $p->cost; $base = $cliente && isset($out['cliente']) ? $out['cliente']['precio'] / (1 + (float) $p->iva / 100) : $p->precioLista($lista);
            $out['costo'] = $costo > 0 ? $costo : null;
            $out['margen'] = $costo > 0 && $base > 0 ? round(($base - $costo) / $base * 100, 1) : null;
        }
        return $out;
    }

    public function ficha(Product $p, User $u, int $lista = 1, ?Contact $cliente = null): array
    {
        $f = $this->fila($p, $u, $lista, $cliente);
        $f['depositos'] = $p->stocks()->with('deposito:id,nombre')->get()->map(fn($s) => ['deposito' => $s->deposito?->nombre, 'cantidad' => (float) $s->cantidad])->filter(fn($s) => $s['deposito'])->values()->all();
        $f['descripcion'] = $p->description;
        $f['ultima_venta'] = Comprobante::ventas()->emitidos()->whereHas('items', fn($i) => $i->where('product_id', $p->id))->max('fecha');
        if ($u->veProveedores()) $f['proveedores'] = $this->proveedoresDe($p);
        return $f;
    }

    // A qué proveedores comprar: los que lo tienen en su lista (lo aprendido de las facturas) del más barato al más caro, con la fecha
    // del precio, cuándo le compraste por última vez a cada uno, cuál fue el último, y cuánto más caro es cada uno que el mejor.
    public function proveedoresDe(Product $p): array
    {
        $cot = $p->cotizacion();
        $filas = [];
        foreach (ProveedorArticulo::with('contact:id,name')->where('product_id', $p->id)->whereNotNull('ultimo_costo')->get() as $pa) {
            $filas[$pa->contact_id] = ['contact_id' => $pa->contact_id, 'proveedor' => $pa->contact?->name, 'costo' => (float) $pa->ultimo_costo, 'fecha' => $pa->ultima_fecha?->toDateString() ?? null, 'codigo' => $pa->codigo, 'ultima_compra' => null, 'moneda' => 'ARS'];
        }
        // Lo comprado de verdad: la última compra registrada a cada proveedor.
        $compras = DB::table('comprobante_items')->join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')
            ->where('comprobantes.business_id', $p->business_id)->where('comprobantes.direccion', 'compra')->where('comprobantes.estado', 'emitido')->whereIn('comprobantes.tipo', ['FA', 'FB', 'FC', 'FE'])
            ->where('comprobante_items.product_id', $p->id)->orderByDesc('comprobantes.fecha')->orderByDesc('comprobantes.id')
            ->get(['comprobantes.contact_id', 'comprobantes.fecha', 'comprobante_items.precio_unit', 'comprobante_items.descuento', 'comprobantes.moneda', 'comprobantes.cotizacion']);
        foreach ($compras->unique('contact_id') as $c) {
            $costo = round((float) $c->precio_unit * (1 - (float) $c->descuento / 100) * (($c->moneda ?? 'ARS') === 'USD' ? ((float) $c->cotizacion ?: $cot) : 1), 2);
            $f = $filas[$c->contact_id] ?? ['contact_id' => $c->contact_id, 'proveedor' => Contact::withoutGlobalScopes()->whereKey($c->contact_id)->value('name'), 'costo' => $costo, 'fecha' => (string) $c->fecha, 'codigo' => null, 'moneda' => $c->moneda ?? 'ARS'];
            $f['ultima_compra'] = (string) $c->fecha;
            if (! $f['fecha'] || $f['fecha'] < (string) $c->fecha) { $f['costo'] = $costo; $f['fecha'] = (string) $c->fecha; }
            $filas[$c->contact_id] = $f;
        }
        $lista = collect($filas)->filter(fn($f) => $f['costo'] > 0)->sortBy('costo')->values();
        $mejor = (float) ($lista->first()['costo'] ?? 0);
        $ultimo = $compras->first()?->contact_id;
        return $lista->map(fn($f) => $f + ['mas_caro_pct' => $mejor > 0 ? round(($f['costo'] - $mejor) / $mejor * 100, 1) : 0, 'es_ultimo' => $f['contact_id'] === $ultimo, 'costo_usd' => $cot > 1 ? round($f['costo'] / $cot, 2) : null])->all();
    }

    // La cotización del simulador: queda guardada 30 días con un link público al PDF, para mandarla por WhatsApp.
    public function cotizar(User $u, array $d): CotizacionConsultor
    {
        $items = collect($d['items'])->map(function ($i) {
            $p = ! empty($i['id']) ? Product::find($i['id']) : null;
            return ['id' => $p?->id, 'nombre' => $i['nombre'] ?? $p?->name, 'cantidad' => (float) $i['cantidad'], 'precio' => round((float) $i['precio'], 2), 'unit' => $p?->unit];
        })->filter(fn($i) => $i['cantidad'] > 0)->values();
        abort_if($items->isEmpty(), 422, 'La lista está vacía.');
        $cli = ! empty($d['contact_id']) ? Contact::find($d['contact_id']) : null;
        return CotizacionConsultor::create(['business_id' => $u->business_id, 'user_id' => $u->id, 'contact_id' => $cli?->id, 'token' => Str::random(32), 'cliente' => $d['cliente'] ?? $cli?->name, 'telefono' => preg_replace('/\D/', '', (string) ($d['telefono'] ?? $cli?->mobile ?? $cli?->phone ?? '')) ?: null,
            'lista' => (int) ($d['lista'] ?? 1), 'items' => $items->all(), 'total' => round($items->sum(fn($i) => $i['cantidad'] * $i['precio']), 2), 'expira_en' => now()->addDays(30)]);
    }

    public function textoWhatsapp(CotizacionConsultor $c): string
    {
        $n = fn($v) => number_format((float) $v, 2, ',', '.');
        return "Hola" . ($c->cliente ? " {$c->cliente}" : '') . "! Te paso la cotización de {$c->business->name}: " . count($c->items) . ' artículo(s), total $ ' . $n($c->total) . ".\n" . url("/q/{$c->token}") . "\n(No es un comprobante; precios válidos por 30 días salvo cambio.)";
    }

    // ---- Pantallas del salón (el Consultor en modo clientes) ----
    public function pantallas(Business $b): array { return array_values($b->pos['pantallas'] ?? []); }

    public function crearPantalla(Business $b, string $nombre, int $lista): array
    {
        $pos = $b->pos ?? []; $p = ['id' => Str::random(8), 'token' => Str::random(40), 'nombre' => $nombre ?: 'Pantalla', 'lista' => max(1, min(6, $lista)), 'creada' => now()->toDateString()];
        $pos['pantallas'] = [...($pos['pantallas'] ?? []), $p]; $b->update(['pos' => $pos]);
        return $p;
    }

    public function editarPantalla(Business $b, string $id, array $d): void
    {
        $pos = $b->pos ?? [];
        $pos['pantallas'] = collect($pos['pantallas'] ?? [])->map(fn($p) => $p['id'] === $id ? array_merge($p, array_filter(['nombre' => $d['nombre'] ?? null, 'lista' => isset($d['lista']) ? max(1, min(6, (int) $d['lista'])) : null])) : $p)->values()->all();
        $b->update(['pos' => $pos]);
    }

    public function bajaPantalla(Business $b, string $id): void
    {
        $pos = $b->pos ?? []; $pos['pantallas'] = collect($pos['pantallas'] ?? [])->reject(fn($p) => $p['id'] === $id)->values()->all(); $b->update(['pos' => $pos]);
    }

    public static function pantallaPorToken(string $token): ?array
    {
        if (strlen($token) < 20) return null;
        foreach (Business::withoutGlobalScopes()->whereNotNull('pos')->where('pos', 'like', "%{$token}%")->get() as $b) {
            foreach ($b->pos['pantallas'] ?? [] as $p) if (hash_equals($p['token'], $token)) return ['business' => $b, 'pantalla' => $p];
        }
        return null;
    }

    // El catálogo que baja la pantalla del salón: sólo lectura, sin costos ni cantidades; lo discontinuado no va.
    public function catalogoSalon(Business $b, int $lista): array
    {
        $ri = ($b->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        return Product::withoutGlobalScopes()->where('business_id', $b->id)->where('active', true)->whereNotIn('tipo', ['insumo'])->orderBy('name')->get()
            ->map(fn($p) => ['id' => $p->id, 'n' => $p->name, 'c' => $p->sku, 'b' => $p->barcode, 'plu' => $p->plu, 'u' => $p->unit, 'img' => $p->imagen, 'p' => round($p->precioLista($lista) * (1 + (float) $p->iva / 100), 2), 'pesable' => (bool) $p->pesable,
                'promo' => (float) $p->desc_cant_pct > 0 && (float) $p->desc_cant_min > 0 ? ['min' => (float) $p->desc_cant_min, 'pct' => (float) $p->desc_cant_pct] : null])->values()->all();
    }
}
