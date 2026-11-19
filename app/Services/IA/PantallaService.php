<?php

namespace App\Services\IA;

use App\Mcp\Busca;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

// Fase 119.1: Bigcito sabe dónde está parado el usuario y puede llevarlo a cualquier pantalla.
// - contexto(): de la URL saca qué está viendo (la ficha de un cliente, una factura, un artículo, una sección del menú)
//   para que «facturale a este», «¿cuánto me debe?» o «imprimilo» se entiendan sin nombrar nada.
// - pantallas() / buscarPantalla(): el mapa de pantallas que ese usuario puede abrir (menú, secciones y formularios de alta).
// - ficha(): la URL de la ficha de un cliente, proveedor, artículo, comprobante, orden…
class PantallaService
{
    // Formularios y pantallas que no están en el menú pero se piden por nombre.
    public const ATAJOS = [
        ['label' => 'Nueva factura', 'ruta' => '/comprobantes/nuevo', 'modulo' => 'comprobantes', 'accion' => 'crear', 'palabras' => 'facturar nuevo comprobante emitir factura hacer una factura'],
        ['label' => 'Nuevo presupuesto', 'ruta' => '/comprobantes/nuevo?tipo=PRE', 'modulo' => 'comprobantes', 'accion' => 'crear', 'palabras' => 'presupuestar cotizar cotización'],
        ['label' => 'Nuevo remito', 'ruta' => '/comprobantes/nuevo?tipo=REM', 'modulo' => 'comprobantes', 'accion' => 'crear', 'palabras' => 'remitar entrega'],
        ['label' => 'Nueva orden de compra', 'ruta' => '/proveedores/ordenes/nueva', 'modulo' => 'proveedores', 'accion' => 'crear', 'palabras' => 'pedir a proveedor pedido de compra'],
        ['label' => 'Nueva factura de compra', 'ruta' => '/proveedores/compras/nueva', 'modulo' => 'proveedores', 'accion' => 'crear', 'palabras' => 'cargar compra cargar factura de proveedor'],
        ['label' => 'Suscripción', 'ruta' => '/suscripcion', 'modulo' => 'configuracion', 'palabras' => 'plan pago del sistema débito automático'],
        ['label' => 'Ayuda', 'ruta' => '/ayuda', 'modulo' => null, 'palabras' => 'manual guías cómo se hace'],
        ['label' => 'Propuestas de Bigcito', 'ruta' => '/propuestas', 'modulo' => 'dashboard', 'palabras' => 'propuestas agentes bandeja'],
    ];

    // Lo que el usuario está viendo, a partir de la URL del navegador. null si no hay nada que decir.
    public function contexto(User $u, ?string $url): ?array
    {
        $url = (string) $url; if ($url === '') return null;
        $path = '/' . trim((string) parse_url($url, PHP_URL_PATH), '/');
        $ficha = $this->fichaDeUrl($u, $path);
        if ($ficha) return $ficha;
        if ($p = $this->pantallaDeUrl($u, $path)) return ['tipo' => 'pantalla', 'nombre' => $p['label'], 'url' => $path, 'texto' => 'Pantalla ' . $p['label'] . (! empty($p['ayuda']) ? " ({$p['ayuda']})" : '') . '.'];
        return null;
    }

    private function fichaDeUrl(User $u, string $path): ?array
    {
        $ver = fn(string $m) => $u->puede($m, 'ver');
        try {
            if (preg_match('#^/clientes/(\d+)$#', $path, $m) && $ver('clientes') && ($c = Contact::customers()->find((int) $m[1]))) {
                $vencido = (float) Comprobante::ventas()->pendientesCobro()->where('contact_id', $c->id)->where('fecha_vto', '<', today()->toDateString())->sum('saldo');
                return ['tipo' => 'cliente', 'id' => $c->id, 'nombre' => $c->name, 'url' => $path,
                    'texto' => "Ficha del cliente «{$c->name}» (id {$c->id}" . ($c->cuit ? ", CUIT {$c->cuit}" : '') . '). Saldo en cuenta corriente: ' . Busca::pesos($c->balance) . ($vencido > 0 ? ', vencido ' . Busca::pesos($vencido) : '') . ($c->phone ? ". Teléfono {$c->phone}" : '') . '.'];
            }
            if (preg_match('#^/proveedores/(\d+)$#', $path, $m) && $ver('proveedores') && ($c = Contact::suppliers()->find((int) $m[1]))) {
                return ['tipo' => 'proveedor', 'id' => $c->id, 'nombre' => $c->name, 'url' => $path,
                    'texto' => "Ficha del proveedor «{$c->name}» (id {$c->id}" . ($c->cuit ? ", CUIT {$c->cuit}" : '') . '). Le debemos: ' . Busca::pesos($c->balance) . '.'];
            }
            if (preg_match('#^/stock/(\d+)$#', $path, $m) && $ver('stock') && ($p = Product::find((int) $m[1]))) {
                return ['tipo' => 'articulo', 'id' => $p->id, 'nombre' => $p->name, 'url' => $path,
                    'texto' => "Ficha del artículo «{$p->name}» (id {$p->id}" . ($p->sku ? ", código {$p->sku}" : '') . '). Precio ' . Busca::pesos($p->price) . ', stock ' . Busca::cant($p->stock) . ($u->puede('stock', 'editar') ? ', costo ' . Busca::pesos($p->cost) : '') . '.'];
            }
            if (preg_match('#^/comprobantes/(\d+)$#', $path, $m) && $ver('comprobantes') && ($c = Comprobante::ventas()->with('contact:id,name')->find((int) $m[1]))) {
                return ['tipo' => 'comprobante', 'id' => $c->id, 'nombre' => $c->nombreTipo() . ' ' . ($c->numeroFormateado() ?? '(borrador)'), 'url' => $path, 'cliente' => $c->contact?->name,
                    'texto' => $c->nombreTipo() . ' ' . ($c->numeroFormateado() ?? '(borrador)') . " (id {$c->id}) de " . ($c->contact?->name ?? 'Consumidor final') . ', del ' . $c->fecha->format('d/m/Y') . ', total ' . Busca::pesos($c->total) . ((float) $c->saldo > 0 ? ', saldo pendiente ' . Busca::pesos($c->saldo) : '') . ", estado {$c->estado}."];
            }
            if (preg_match('#^/proveedores/compras/(\d+)$#', $path, $m) && $ver('proveedores') && ($c = Comprobante::compras()->with('contact:id,name')->find((int) $m[1]))) {
                return ['tipo' => 'compra', 'id' => $c->id, 'nombre' => 'Factura de compra ' . ($c->numeroFormateado() ?? ''), 'url' => $path, 'proveedor' => $c->contact?->name,
                    'texto' => 'Factura de compra ' . ($c->numeroFormateado() ?? '') . " (id {$c->id}) de " . ($c->contact?->name ?? 'proveedor') . ', del ' . $c->fecha->format('d/m/Y') . ', total ' . Busca::pesos($c->total) . ((float) $c->saldo > 0 ? ', saldo a pagar ' . Busca::pesos($c->saldo) : '') . '.'];
            }
            if (preg_match('#^/proveedores/ordenes/(\d+)$#', $path, $m) && $ver('proveedores') && ($o = \App\Models\OrdenCompra::with('contact:id,name')->find((int) $m[1]))) {
                return ['tipo' => 'orden_compra', 'id' => $o->id, 'nombre' => "Orden de compra {$o->numero}", 'url' => $path, 'proveedor' => $o->contact?->name,
                    'texto' => "Orden de compra N° {$o->numero} (id {$o->id}) a " . ($o->contact?->name ?? 'proveedor') . ', total ' . Busca::pesos($o->total) . ", estado {$o->estado}."];
            }
            if (preg_match('#^/servicios/(\d+)$#', $path, $m) && $ver('servicios') && ($o = \App\Models\OrdenTrabajo::find((int) $m[1]))) {
                return ['tipo' => 'orden_servicio', 'id' => $o->id, 'nombre' => "Orden de servicio {$o->numero}", 'url' => $path, 'cliente' => $o->nombre,
                    'texto' => "Orden de servicio N° {$o->numero} (id {$o->id}) de " . ($o->nombre ?: 'cliente') . ': ' . trim(($o->equipo ?? '') . ' ' . ($o->marca_modelo ?? '')) . ", estado {$o->estado}" . ($o->falla ? '. Falla: ' . Str::limit($o->falla, 120) : '') . '.'];
            }
            if (preg_match('#^/hoteleria/estadias/(\d+)$#', $path, $m) && $ver('hoteleria') && ($e = \App\Models\Estadia::find((int) $m[1]))) {
                return ['tipo' => 'estadia', 'id' => $e->id, 'nombre' => "Estadía de {$e->nombre}", 'url' => $path, 'cliente' => $e->nombre,
                    'texto' => "Estadía de {$e->nombre} (id {$e->id}), del " . \Carbon\Carbon::parse($e->desde)->format('d/m') . ' al ' . \Carbon\Carbon::parse($e->hasta)->format('d/m') . ", estado {$e->estado}."];
            }
            if (preg_match('#^/gastronomia/comandas/(\d+)$#', $path, $m) && $ver('gastronomia') && ($c = \App\Models\Comanda::find((int) $m[1]))) {
                return ['tipo' => 'comanda', 'id' => $c->id, 'nombre' => "Comanda {$c->numero}", 'url' => $path,
                    'texto' => "Comanda N° {$c->numero} (id {$c->id}), {$c->tipo}, total " . Busca::pesos($c->total) . ", estado {$c->estado}."];
            }
        } catch (\Throwable $e) { report($e); }
        return null;
    }

    // La sección del menú a la que pertenece la URL (gana la ruta más larga que coincide).
    private function pantallaDeUrl(User $u, string $path): ?array
    {
        $mejor = null;
        foreach ($this->pantallas($u) as $p) {
            $r = (string) parse_url($p['ruta'], PHP_URL_PATH);
            if (str_contains($p['ruta'], '?')) continue;
            if ($path === $r || str_starts_with($path, $r . '/')) { if (! $mejor || strlen($r) > strlen((string) parse_url($mejor['ruta'], PHP_URL_PATH))) $mejor = $p; }
        }
        return $mejor;
    }

    // Todas las pantallas que este usuario puede abrir: [label, ruta, grupo, palabras, ayuda].
    public function pantallas(User $u): array
    {
        $visibles = $u->modulosVisibles(); $out = [];
        foreach (config('erp.menu', []) as $m) {
            if (! in_array($m['modulo'], $visibles, true)) continue;
            if (isset($m['solo_sin']) && in_array($m['solo_sin'], $visibles, true)) continue;
            $out[] = ['label' => $m['label'], 'ruta' => $m['ruta'], 'grupo' => '', 'palabras' => $m['key'], 'ayuda' => null];
            foreach (config("erp.secciones.{$m['key']}", []) as $it) {
                $modulo = $it['modulo'] ?? $m['modulo'];
                if (! in_array($modulo, $visibles, true)) continue;
                if (isset($it['accion']) && ! $u->puede($modulo, $it['accion'])) continue;
                if (isset($it['usa']) && ! $u->business?->usa($it['usa'])) continue;
                $out[] = ['label' => $it['label'], 'ruta' => $it['ruta'], 'grupo' => $m['label'], 'palabras' => ($it['palabras'] ?? '') . ' ' . $it['key'], 'ayuda' => $it['ayuda'] ?? null];
            }
        }
        foreach (config('erp.nombres_viejos', []) as $viejo => $nuevo) foreach ($out as $p) if ($p['label'] === $nuevo && $p['grupo'] === '') { $out[] = ['label' => $viejo, 'ruta' => $p['ruta'], 'grupo' => '', 'palabras' => '', 'ayuda' => null, 'alias_de' => $nuevo]; break; }
        foreach (self::ATAJOS as $a) {
            if ($a['modulo'] !== null && ! in_array($a['modulo'], $visibles, true)) continue;
            if (isset($a['accion']) && ! $u->puede($a['modulo'], $a['accion'])) continue;
            $out[] = ['label' => $a['label'], 'ruta' => $a['ruta'], 'grupo' => 'Atajos', 'palabras' => $a['palabras'] ?? '', 'ayuda' => null];
        }
        return $out;
    }

    private static function norm(string $s): string { return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', Str::lower(Str::ascii($s))))); }

    // La pantalla que mejor coincide con lo pedido ("cerrar caja", "pedidos web", "nueva factura"). Devuelve [mejor, alternativas].
    public function buscarPantalla(User $u, string $texto): array
    {
        $q = self::norm($texto); if ($q === '') return [null, []];
        $q = trim(preg_replace('/^(la |el |los |las )?(pantalla|seccion|pagina|menu|listado|lista) (de |del |de la |de los |de las )?/', '', $q)) ?: $q;
        $palabras = array_filter(explode(' ', $q), fn($p) => strlen($p) > 2 && ! in_array($p, ['los', 'las', 'del', 'una', 'uno', 'para', 'por', 'con', 'que'], true));
        $puntos = [];
        foreach ($this->pantallas($u) as $i => $p) {
            $label = self::norm($p['label']); $todo = $label . ' ' . self::norm($p['palabras'] . ' ' . $p['grupo']);
            $s = 0;
            if ($label === $q) $s += 100;
            elseif (str_contains($label, $q)) $s += 60;
            elseif (str_contains($q, $label)) $s += 50;
            foreach ($palabras as $w) { $raiz = substr($w, 0, max(4, strlen($w) - 2)); if (str_contains($label, $raiz)) $s += 12; elseif (str_contains($todo, $raiz)) $s += 6; }
            if ($s > 0) $puntos[] = [$s - strlen($label) / 100, $p];
        }
        usort($puntos, fn($a, $b) => $b[0] <=> $a[0]);
        if (! $puntos || $puntos[0][0] < 10) return [null, array_map(fn($x) => $x[1]['label'], array_slice($puntos, 0, 5))];
        return [$puntos[0][1], array_values(array_unique(array_map(fn($x) => $x[1]['label'], array_slice($puntos, 1, 4))))];
    }

    // La ficha de algo por nombre, número o id. $tipo: cliente | proveedor | articulo | comprobante | compra | orden_compra | orden_servicio.
    public function ficha(User $u, string $tipo, string $buscar): ?array
    {
        $buscar = trim($buscar);
        switch ($tipo) {
            case 'cliente': if (! $u->puede('clientes')) return null; $c = Busca::cliente($buscar); return $c ? ['titulo' => "Cliente {$c->name}", 'ruta' => "/clientes/{$c->id}"] : null;
            case 'proveedor': if (! $u->puede('proveedores')) return null; $c = Busca::proveedor($buscar); return $c ? ['titulo' => "Proveedor {$c->name}", 'ruta' => "/proveedores/{$c->id}"] : null;
            case 'articulo': if (! $u->puede('stock')) return null; $p = Busca::articulo($buscar); return $p ? ['titulo' => "Artículo {$p->name}", 'ruta' => "/stock/{$p->id}"] : null;
            case 'comprobante': if (! $u->puede('comprobantes')) return null; $c = Busca::comprobante($buscar, 'venta') ?? $this->ultimoDe($buscar, 'venta'); return $c ? ['titulo' => $c->nombreTipo() . ' ' . ($c->numeroFormateado() ?? ''), 'ruta' => "/comprobantes/{$c->id}", 'imprimir' => "/comprobantes/{$c->id}/imprimir"] : null;
            case 'compra': if (! $u->puede('proveedores')) return null; $c = Busca::comprobante($buscar, 'compra') ?? $this->ultimoDe($buscar, 'compra'); return $c ? ['titulo' => 'Factura de compra ' . ($c->numeroFormateado() ?? ''), 'ruta' => "/proveedores/compras/{$c->id}"] : null;
            case 'orden_compra': if (! $u->puede('proveedores')) return null; $n = (int) preg_replace('/\D/', '', $buscar); $o = $n ? (\App\Models\OrdenCompra::where('numero', $n)->first() ?? \App\Models\OrdenCompra::find($n)) : null; return $o ? ['titulo' => "Orden de compra {$o->numero}", 'ruta' => "/proveedores/ordenes/{$o->id}", 'imprimir' => "/proveedores/ordenes/{$o->id}/imprimir"] : null;
            case 'orden_servicio': if (! $u->puede('servicios')) return null; $n = (int) preg_replace('/\D/', '', $buscar);
                $o = $n ? (\App\Models\OrdenTrabajo::where('numero', $n)->first() ?? \App\Models\OrdenTrabajo::find($n)) : \App\Models\OrdenTrabajo::where('nombre', \App\Support\Sql::like(), "%{$buscar}%")->latest('id')->first();
                return $o ? ['titulo' => "Orden de servicio {$o->numero}", 'ruta' => "/servicios/{$o->id}"] : null;
        }
        return null;
    }

    // "la última factura de López": si no es un número, el último comprobante de ese cliente o proveedor.
    private function ultimoDe(string $buscar, string $direccion): ?Comprobante
    {
        $c = $direccion === 'venta' ? Busca::cliente($buscar) : Busca::proveedor($buscar);
        return $c ? Comprobante::query()->where('direccion', $direccion)->where('contact_id', $c->id)->where('estado', '!=', 'borrador')->orderByDesc('fecha')->orderByDesc('id')->first() : null;
    }
}
