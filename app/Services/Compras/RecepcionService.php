<?php

namespace App\Services\Compras;

use App\Models\CodigoPistola;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Product;
use App\Models\Recepcion;
use App\Models\RecepcionItem;
use App\Models\ReclamoCompra;
use App\Support\Gs1;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Fase 31.5: controlar lo que bajó del camión contra la factura, con la pistola. Lo que se escucha (bien / mal) lo decide el
// resultado de leer(); acá está lo que se cuenta, lo que se aprende y lo que pasa al cerrar: cantidades recibidas, lotes y el reclamo.
class RecepcionService
{
    public const DEFAULT = ['exigir_control' => false];

    public function __construct(private \App\Services\Stock\StockService $stock) {}

    public function config(?\App\Models\Business $b): array
    {
        return array_replace(self::DEFAULT, ($b ? \App\Models\Business::whereKey($b->id)->value('compras_config') : null) ?? []);
    }

    public static function controlable(Comprobante $c): bool
    {
        return $c->direccion === 'compra' && $c->def()['stock'] && ! $c->solo_contable && ! $c->esNotaCredito() && $c->estado !== 'anulado';
    }

    // La recepción abierta de esta compra (retoma donde ibas) o una nueva con los renglones de la factura y sus cantidades.
    public function abrir(Comprobante $c, bool $nueva = false): Recepcion
    {
        abort_unless(self::controlable($c), 422, 'Esta compra no mueve mercadería: no hay nada que controlar.');
        $r = Recepcion::where('comprobante_id', $c->id)->orderByDesc('id')->first();
        if ($r && $r->abierta() && ! $nueva) return $r;
        if ($r && ! $nueva) return $r; // cerrada: se muestra el resultado; «Volver a controlar» abre otra
        return DB::transaction(function () use ($c) {
            $r = Recepcion::create(['business_id' => $c->business_id, 'comprobante_id' => $c->id, 'user_id' => Auth::id(), 'estado' => 'abierta']);
            foreach ($c->items as $it) {
                RecepcionItem::create(['recepcion_id' => $r->id, 'comprobante_item_id' => $it->id, 'product_id' => $it->product_id, 'descripcion' => $it->descripcion, 'esperado' => $it->cantidad, 'contado' => 0, 'lote' => $it->lote, 'vencimiento' => $it->vencimiento]);
            }
            return $r;
        });
    }

    public function estado(Recepcion $r): array
    {
        $r->loadMissing(['items.product:id,name,sku,barcode,perecedero,unidades_bulto', 'comprobante.contact:id,name,phone,mobile,email']);
        $esp = 0; $ok = 0; $items = [];
        foreach ($r->items as $i) {
            $e = (float) $i->esperado; $c = (float) $i->contado; $esp += $e; $ok += min($e, $c);
            $items[] = ['id' => $i->id, 'comprobante_item_id' => $i->comprobante_item_id, 'product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'sku' => $i->product?->sku, 'barcode' => $i->product?->barcode,
                'esperado' => $e, 'contado' => $c, 'diferencia' => round($c - $e, 3), 'estado' => $c <= 0 ? 'falta' : ($c < $e ? 'parcial' : ($c == $e ? 'ok' : 'sobra')),
                'lote' => $i->lote, 'vencimiento' => $i->vencimiento?->toDateString(), 'perecedero' => (bool) $i->product?->perecedero, 'bulto' => $i->product?->unidades_bulto, 'resolucion' => $i->resolucion, 'no_facturado' => $i->comprobante_item_id === null];
        }
        $c = $r->comprobante;
        return ['id' => $r->id, 'estado' => $r->estado, 'lecturas' => $r->lecturas, 'ultimas' => array_slice($r->ultimas ?? [], -8), 'items' => $items,
            'esperado' => $esp, 'avance' => $esp > 0 ? round($ok / $esp * 100) : 0, 'completa' => $esp > 0 && $ok >= $esp,
            'diferencias' => count(array_filter($items, fn($i) => $i['diferencia'] != 0 || $i['no_facturado'])),
            'compra' => ['id' => $c->id, 'nombre' => $c->nombreTipo(), 'numero' => $c->numeroFormateado(), 'fecha' => $c->fecha->format('d/m/Y'), 'estado' => $c->estado, 'proveedor' => $c->contact?->name, 'telefono' => $c->contact?->mobile ?: $c->contact?->phone, 'email' => $c->contact?->email],
            'cerrada_en' => $r->cerrada_en?->format('d/m/Y H:i')];
    }

    // Una lectura de la pistola. Devuelve qué pasó para que la pantalla suene y muestre:
    //   ok | sobra (contó, pero ya pasó lo facturado) | repetido (el código está en dos renglones: elegir) | no_esta (es de un artículo que no está en la factura) | desconocido.
    public function leer(Recepcion $r, string $codigo, float $mult = 1): array
    {
        abort_unless($r->abierta(), 422, 'Este control ya está cerrado.');
        $codigo = trim($codigo);
        if ($codigo === '') return ['estado' => 'desconocido', 'codigo' => $codigo];
        $gs1 = Gs1::parse($codigo);
        $clave = $gs1['gtin'] ?? $codigo;
        $cantidad = max(1, $mult) * ($gs1['cantidad'] ?? 1);
        $productos = $this->productosPorCodigo($clave);
        if ($gs1 && ! $productos && $gs1['gtin'] && strlen($gs1['gtin']) === 13) $productos = $this->productosPorCodigo('0' . $gs1['gtin']);
        $items = $r->items()->whereIn('product_id', $productos)->get();
        if ($items->isEmpty()) {
            $p = $productos ? Product::find($productos[0]) : null;
            return ['estado' => $p ? 'no_esta' : 'desconocido', 'codigo' => $codigo, 'clave' => $clave, 'producto' => $p ? ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku] : null, 'cantidad' => $cantidad, 'gs1' => $gs1];
        }
        if ($items->pluck('product_id')->unique()->count() > 1) {
            return ['estado' => 'repetido', 'codigo' => $codigo, 'clave' => $clave, 'cantidad' => $cantidad, 'gs1' => $gs1, 'opciones' => $items->map(fn($i) => ['id' => $i->id, 'descripcion' => $i->descripcion])->values()->all()];
        }
        $item = $items->first(fn($i) => (float) $i->contado < (float) $i->esperado) ?? $items->first();
        return $this->sumar($r, $item, $cantidad, $codigo, $gs1);
    }

    // El código era de este renglón: se cuenta y, si tiene artículo, queda aprendido para la próxima (sin tocar la ficha).
    public function asignar(Recepcion $r, string $codigo, int $itemId, float $cantidad = 1, bool $aprender = true): array
    {
        abort_unless($r->abierta(), 422, 'Este control ya está cerrado.');
        $item = $r->items()->findOrFail($itemId);
        $gs1 = Gs1::parse($codigo); $clave = $gs1['gtin'] ?? trim($codigo);
        if ($aprender && $item->product_id && $clave !== '') {
            CodigoPistola::updateOrCreate(['business_id' => $r->business_id, 'codigo' => $clave], ['product_id' => $item->product_id, 'user_id' => Auth::id()]);
        }
        return $this->sumar($r, $item, $cantidad, $codigo, $gs1);
    }

    private function sumar(Recepcion $r, RecepcionItem $item, float $cantidad, string $codigo, ?array $gs1): array
    {
        $item->contado = (float) $item->contado + $cantidad;
        if ($gs1) { if (! $item->lote && $gs1['lote']) $item->lote = $gs1['lote']; if (! $item->vencimiento && $gs1['vencimiento']) $item->vencimiento = $gs1['vencimiento']; }
        $item->save();
        $u = $r->ultimas ?? []; $u[] = ['codigo' => $codigo, 'item' => $item->descripcion, 'cantidad' => $cantidad, 'hora' => now()->format('H:i')];
        $r->forceFill(['lecturas' => $r->lecturas + 1, 'ultimas' => array_slice($u, -30)])->save();
        $sobra = (float) $item->contado > (float) $item->esperado;
        return ['estado' => $sobra ? 'sobra' : 'ok', 'codigo' => $codigo, 'item_id' => $item->id, 'descripcion' => $item->descripcion, 'contado' => (float) $item->contado, 'esperado' => (float) $item->esperado, 'cantidad' => $cantidad, 'gs1' => $gs1, 'lote' => $item->lote, 'vencimiento' => $item->vencimiento?->toDateString()];
    }

    // Lo que no tiene código se cuenta a mano: la cantidad que se escribe es la contada, no se suma.
    public function contar(Recepcion $r, int $itemId, float $contado): RecepcionItem
    {
        abort_unless($r->abierta(), 422, 'Este control ya está cerrado.');
        $item = $r->items()->findOrFail($itemId);
        $item->forceFill(['contado' => max(0, $contado)])->save();
        return $item;
    }

    public function lote(Recepcion $r, int $itemId, ?string $lote, ?string $vencimiento): RecepcionItem
    {
        $item = $r->items()->findOrFail($itemId);
        $item->forceFill(['lote' => $lote ?: null, 'vencimiento' => $vencimiento ?: null])->save();
        return $item;
    }

    // Llegó algo que no está en la factura: se anota para reclamarlo (no entra al stock hasta que esté facturado).
    public function agregar(Recepcion $r, ?int $productId, ?string $descripcion, float $cantidad): RecepcionItem
    {
        abort_unless($r->abierta(), 422, 'Este control ya está cerrado.');
        $p = $productId ? Product::findOrFail($productId) : null;
        return RecepcionItem::create(['recepcion_id' => $r->id, 'comprobante_item_id' => null, 'product_id' => $p?->id, 'descripcion' => $descripcion ?: $p?->name ?: 'Sin descripción', 'esperado' => 0, 'contado' => max(0, $cantidad)]);
    }

    public function quitar(Recepcion $r, int $itemId): void
    {
        $item = $r->items()->findOrFail($itemId);
        abort_if($item->comprobante_item_id, 422, 'Los renglones de la factura no se quitan: ponele cantidad 0.');
        $item->delete();
    }

    // Terminar el control. Por renglón con diferencia: entra lo contado, entra lo facturado o queda pendiente (entra lo contado y
    // el resto se reclama). Si la compra ya estaba registrada, el stock se corrige acá mismo. Devuelve la recepción y el reclamo (si hubo).
    public function cerrar(Recepcion $r, array $resoluciones = [], string $todas = null, bool $reclamar = true): array
    {
        abort_unless($r->abierta(), 422, 'Este control ya está cerrado.');
        return DB::transaction(function () use ($r, $resoluciones, $todas, $reclamar) {
            $c = $r->comprobante()->with('items')->first();
            $filas = [];
            foreach ($r->items as $i) {
                $dif = $i->diferencia();
                if (! $i->comprobante_item_id) { if ((float) $i->contado > 0) $filas[] = ['tipo' => 'no_facturado', 'descripcion' => $i->descripcion, 'product_id' => $i->product_id, 'facturado' => 0, 'llego' => (float) $i->contado, 'diferencia' => (float) $i->contado]; continue; }
                $res = $dif == 0 ? 'facturado' : ($resoluciones[$i->id] ?? $todas ?? 'pendiente');
                if (! in_array($res, ['contado', 'facturado', 'pendiente'])) $res = 'pendiente';
                $i->forceFill(['resolucion' => $res])->save();
                $ci = $c->items->firstWhere('id', $i->comprobante_item_id);
                if (! $ci) continue;
                $recibe = $res === 'facturado' ? (float) $ci->cantidad : (float) $i->contado;
                $antes = (float) ($ci->cantidad_recibida ?? $ci->cantidad);
                $ci->forceFill(['cantidad_recibida' => $recibe, 'lote' => $i->lote ?: $ci->lote, 'vencimiento' => $i->vencimiento?->toDateString() ?: $ci->vencimiento])->save();
                // Ya estaba registrada: el stock entró con lo facturado; se corrige por la diferencia.
                if ($c->estado === 'emitido' && $c->stock_impactado && $ci->product_id && abs($recibe - $antes) > 0.0005 && ($p = Product::find($ci->product_id))) {
                    $this->stock->mover($p, $recibe - $antes, \App\Models\Deposito::porDefecto($c->business_location_id), $recibe > $antes ? 'in' : 'out', "Recepción {$c->nombreTipo()} {$c->numeroFormateado()}: " . ($recibe > $antes ? 'sobrante' : 'faltante') . ' contado', $c, (float) $ci->precio_unit * (1 - (float) $ci->descuento / 100), $c->business_location_id, ['lote' => $ci->lote, 'vencimiento' => $ci->vencimiento]);
                }
                if ($dif != 0 && $res !== 'facturado') $filas[] = ['tipo' => $dif < 0 ? ($res === 'pendiente' ? 'pendiente' : 'falto') : 'sobro', 'descripcion' => $i->descripcion, 'product_id' => $i->product_id, 'facturado' => (float) $i->esperado, 'llego' => (float) $i->contado, 'diferencia' => $dif];
                elseif ($dif != 0) $filas[] = ['tipo' => $dif < 0 ? 'falto' : 'sobro', 'descripcion' => $i->descripcion, 'product_id' => $i->product_id, 'facturado' => (float) $i->esperado, 'llego' => (float) $i->contado, 'diferencia' => $dif, 'entro_facturado' => true];
            }
            $r->forceFill(['estado' => 'cerrada', 'cerrada_en' => now()])->save();
            $reclamo = null;
            if ($reclamar && $filas) {
                $reclamo = ReclamoCompra::create(['business_id' => $c->business_id, 'contact_id' => $c->contact_id, 'comprobante_id' => $c->id, 'recepcion_id' => $r->id, 'estado' => 'abierto', 'detalle' => $filas, 'texto' => $this->textoReclamo($c, $filas)]);
            }
            \App\Models\AuditLog::registrar('editar', $c, "Controló la recepción de {$c->nombreTipo()} {$c->numeroFormateado()}" . ($filas ? ' con ' . count($filas) . ' diferencia(s)' : ' sin diferencias'));
            return ['recepcion' => $r->fresh(), 'reclamo' => $reclamo];
        });
    }

    public function textoReclamo(Comprobante $c, array $filas): string
    {
        $b = $c->business;
        $n = fn($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
        $l = ["Hola {$c->contact?->name}, controlamos la {$c->nombreTipo()} {$c->numeroFormateado()} del {$c->fecha->format('d/m/Y')} y encontramos estas diferencias:"];
        foreach ($filas as $f) {
            $l[] = match ($f['tipo']) {
                'no_facturado' => "• {$f['descripcion']}: llegó {$n($f['llego'])} y no está facturado",
                'sobro' => "• {$f['descripcion']}: facturado {$n($f['facturado'])}, llegó {$n($f['llego'])} (sobran {$n($f['diferencia'])})",
                default => "• {$f['descripcion']}: facturado {$n($f['facturado'])}, llegó {$n($f['llego'])} (faltan {$n(-$f['diferencia'])})",
            };
        }
        $faltan = array_filter($filas, fn($f) => in_array($f['tipo'], ['falto', 'pendiente']));
        $l[] = $faltan ? 'Por favor, mandennos la mercadería que falta o la nota de crédito por esos renglones.' : 'Quedamos a la espera de cómo lo resolvemos.';
        $l[] = "Gracias. {$b->name}";
        return implode("\n", $l);
    }

    // Los reclamos abiertos del proveedor se cierran solos con la nota de crédito: la de esa factura, o una que traiga esos artículos.
    public function cerrarConNotaCredito(Comprobante $nc): int
    {
        if (! $nc->esNotaCredito() || $nc->direccion !== 'compra') return 0;
        $prods = $nc->items->pluck('product_id')->filter()->all();
        $n = 0;
        foreach (ReclamoCompra::abiertos()->where('contact_id', $nc->contact_id)->get() as $rc) {
            $faltantes = collect($rc->detalle)->whereIn('tipo', ['falto', 'pendiente'])->pluck('product_id')->filter();
            $cubre = ($nc->origen_id && $rc->comprobante_id === $nc->origen_id) || ($faltantes->isNotEmpty() && $faltantes->diff($prods)->isEmpty());
            if ($cubre) { $rc->resolver("Nota de crédito {$nc->numeroFormateado()}", Auth::id(), $nc->id); $n++; }
        }
        return $n;
    }

    public function reclamos(bool $soloAbiertos = true)
    {
        return ReclamoCompra::with(['contact:id,name,phone,mobile,email', 'comprobante:id,tipo,punto_venta,numero,numero_proveedor,fecha'])->when($soloAbiertos, fn($q) => $q->abiertos())->orderByDesc('id')->limit(100)->get()
            ->map(fn($r) => ['id' => $r->id, 'estado' => $r->estado, 'proveedor' => $r->contact?->name, 'telefono' => preg_replace('/\D/', '', (string) ($r->contact?->mobile ?: $r->contact?->phone)), 'email' => $r->contact?->email, 'comprobante' => $r->comprobante ? $r->comprobante->nombreTipo() . ' ' . $r->comprobante->numeroFormateado() : null, 'comprobante_id' => $r->comprobante_id,
                'detalle' => $r->detalle, 'texto' => $r->texto, 'fecha' => $r->created_at->format('d/m/Y'), 'resolucion' => $r->resolucion, 'resuelto_en' => $r->resuelto_en?->format('d/m/Y')]);
    }

    // De qué artículos es este código: lo aprendido manda; después el código de barras y el SKU de la ficha.
    private function productosPorCodigo(string $clave): array
    {
        $ids = CodigoPistola::where('codigo', $clave)->pluck('product_id')->all();
        if ($ids) return $ids;
        return Product::where(fn($w) => $w->where('barcode', $clave)->orWhere('sku', $clave))->pluck('id')->all();
    }
}
