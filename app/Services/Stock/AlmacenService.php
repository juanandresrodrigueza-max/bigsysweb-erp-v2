<?php

namespace App\Services\Stock;

use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Models\Deposito;
use App\Models\Product;
use App\Models\StockDeposito;
use App\Models\Ubicacion;
use App\Models\UbicacionMovimiento;
use App\Models\UbicacionStock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Fase 27.4: gestión de almacén. El stock de cada depósito se reparte en ubicaciones (pasillo-estante-nivel); lo que no
// está en ninguna queda "sin ubicar". Guardar, mover, contar, buscar y preparar pedidos con la pistola.
class AlmacenService
{
    private static function n(float $x): string { return rtrim(rtrim(number_format($x, 3, ',', '.'), '0'), ','); }

    public function ubicado(Product $p, Deposito $d): float
    {
        return (float) UbicacionStock::where('product_id', $p->id)->whereIn('ubicacion_id', Ubicacion::where('deposito_id', $d->id)->select('id'))->sum('cantidad');
    }

    public function sinUbicar(Product $p, Deposito $d): float
    {
        $stock = (float) StockDeposito::where('product_id', $p->id)->where('deposito_id', $d->id)->value('cantidad');
        return round($stock - $this->ubicado($p, $d), 3);
    }

    private function registrar(Product $p, ?int $lote, ?Ubicacion $desde, ?Ubicacion $hacia, float $cant, string $motivo): void
    {
        UbicacionMovimiento::create(['business_id' => $p->business_id, 'user_id' => Auth::id(), 'product_id' => $p->id, 'lote_id' => $lote, 'desde_id' => $desde?->id, 'hacia_id' => $hacia?->id, 'cantidad' => $cant, 'motivo' => $motivo]);
    }

    private function sumar(Ubicacion $u, Product $p, ?int $lote, float $cant): UbicacionStock
    {
        $s = UbicacionStock::firstOrNew(['ubicacion_id' => $u->id, 'product_id' => $p->id, 'lote_id' => $lote], ['business_id' => $u->business_id, 'cantidad' => 0]);
        $s->cantidad = round((float) $s->cantidad + $cant, 3); $s->save();
        return $s;
    }

    // Guardar en una ubicación mercadería que está sin ubicar (recepción, stock inicial).
    public function guardar(Ubicacion $u, Product $p, float $cant, ?int $lote = null, string $motivo = 'Guardado'): void
    {
        DB::transaction(function () use ($u, $p, $cant, $lote, $motivo) {
            $libre = $this->sinUbicar($p, $u->deposito);
            if ($cant > $libre + 0.0005) throw ValidationException::withMessages(['cantidad' => "De {$p->name} hay " . self::n(max(0, $libre)) . " sin ubicar en {$u->deposito->nombre}. Si está en otra ubicación, usá Mover."]);
            $this->sumar($u, $p, $lote, $cant);
            $this->registrar($p, $lote, null, $u, $cant, $motivo);
        });
    }

    // Mover entre ubicaciones del mismo depósito.
    public function mover(Ubicacion $desde, Ubicacion $hacia, Product $p, float $cant, ?int $lote = null): void
    {
        abort_if($desde->id === $hacia->id, 422, 'Elegí una ubicación distinta.');
        abort_if($desde->deposito_id !== $hacia->deposito_id, 422, 'Las ubicaciones son de depósitos distintos: usá una transferencia de stock.');
        DB::transaction(function () use ($desde, $hacia, $p, $cant, $lote) {
            $origen = UbicacionStock::where('ubicacion_id', $desde->id)->where('product_id', $p->id)->when($lote, fn($q) => $q->where('lote_id', $lote))->where('cantidad', '>', 0)->orderBy('id')->get();
            if ((float) $origen->sum('cantidad') + 0.0005 < $cant) throw ValidationException::withMessages(['cantidad' => "En {$desde->codigo} hay " . self::n((float) $origen->sum('cantidad')) . " de {$p->name}."]);
            $resto = $cant;
            foreach ($origen as $s) {
                if ($resto <= 0.0005) break;
                $usa = min((float) $s->cantidad, $resto);
                $s->cantidad = round((float) $s->cantidad - $usa, 3); $s->save();
                $this->sumar($hacia, $p, $s->lote_id, $usa);
                $this->registrar($p, $s->lote_id, $desde, $hacia, $usa, 'Movido');
                $resto -= $usa;
            }
        });
    }

    // Conteo de una ubicación: lo contado queda como stock de la ubicación. Si con eso las ubicaciones suman más que el
    // depósito, el depósito se ajusta hacia arriba; si suman menos, la diferencia queda "sin ubicar".
    public function contar(Ubicacion $u, array $conteos): array
    {
        return DB::transaction(function () use ($u, $conteos) {
            $out = [];
            foreach ($conteos as $pid => $cant) {
                $p = Product::findOrFail($pid); $cant = max(0, (float) $cant);
                $actual = (float) UbicacionStock::where('ubicacion_id', $u->id)->where('product_id', $p->id)->sum('cantidad');
                UbicacionStock::where('ubicacion_id', $u->id)->where('product_id', $p->id)->update(['cantidad' => 0]);
                if ($cant > 0) $this->sumar($u, $p, null, $cant);
                if (abs($cant - $actual) > 0.0005) $this->registrar($p, null, $cant < $actual ? $u : null, $cant > $actual ? $u : null, abs($cant - $actual), 'Conteo de ubicación');
                $ubic = $this->ubicado($p, $u->deposito); $stock = (float) StockDeposito::where('product_id', $p->id)->where('deposito_id', $u->deposito_id)->value('cantidad');
                if ($ubic > $stock + 0.0005) app(StockService::class)->ajustar($p, $u->deposito, $ubic, "Conteo de ubicación {$u->codigo}", 'inventario', $u);
                $out[] = ['product_id' => $p->id, 'nombre' => $p->name, 'antes' => $actual, 'contado' => $cant];
            }
            AuditLog::registrar('editar', $u, "Conteo de la ubicación {$u->codigo}: " . count($out) . ' artículos');
            return $out;
        });
    }

    // Después de una salida de stock (venta, baja, transferencia): si las ubicaciones suman más que el depósito, se descuenta
    // de las ubicaciones: primero despacho, después por el orden del recorrido y los lotes que salieron.
    public function consumir(Product $p, ?Deposito $d, array $lotes = []): void
    {
        if (! $d) return;
        $exceso = -$this->sinUbicar($p, $d);
        if ($exceso <= 0.0005) return;
        $filas = UbicacionStock::with('ubicacion')->where('product_id', $p->id)->where('cantidad', '>', 0)->whereIn('ubicacion_id', Ubicacion::where('deposito_id', $d->id)->select('id'))->get()
            ->sortBy(fn($s) => [in_array($s->lote_id, $lotes, true) ? 0 : 1, $s->ubicacion->tipo === 'despacho' ? 0 : 1, $s->ubicacion->orden, $s->ubicacion->codigo]);
        foreach ($filas as $s) {
            if ($exceso <= 0.0005) break;
            $usa = min((float) $s->cantidad, $exceso);
            $s->cantidad = round((float) $s->cantidad - $usa, 3); $s->save();
            $this->registrar($p, $s->lote_id, $s->ubicacion, null, $usa, 'Salida de stock');
            $exceso -= $usa;
        }
    }

    // Lo que lee la pistola: una ubicación (con su contenido) o un artículo (con dónde está).
    public function buscar(string $codigo): ?array
    {
        $c = Ubicacion::normalizar($codigo);
        if ($u = Ubicacion::with('deposito:id,nombre')->where('codigo', $c)->first()) return ['tipo' => 'ubicacion', 'ubicacion' => $this->ficha($u)];
        $p = Product::where('barcode', trim($codigo))->orWhere('sku', trim($codigo))->first();
        return $p ? ['tipo' => 'articulo', 'articulo' => $this->dondeEsta($p)] : null;
    }

    public function ficha(Ubicacion $u): array
    {
        return ['id' => $u->id, 'codigo' => $u->codigo, 'deposito' => $u->deposito?->nombre, 'deposito_id' => $u->deposito_id, 'tipo' => $u->tipo, 'orden' => $u->orden, 'pasillo' => $u->pasillo, 'estante' => $u->estante, 'nivel' => $u->nivel,
            'contenido' => UbicacionStock::with('product:id,name,sku,unit,barcode', 'lote')->where('ubicacion_id', $u->id)->where('cantidad', '>', 0)->get()
                ->map(fn($s) => ['product_id' => $s->product_id, 'nombre' => $s->product?->name, 'sku' => $s->product?->sku, 'unidad' => $s->product?->unit, 'lote_id' => $s->lote_id, 'lote' => $s->lote?->etiqueta(), 'cantidad' => (float) $s->cantidad])->values()];
    }

    public function dondeEsta(Product $p): array
    {
        $ubic = UbicacionStock::with('ubicacion.deposito:id,nombre', 'lote')->where('product_id', $p->id)->where('cantidad', '>', 0)->get()->sortBy(fn($s) => [$s->ubicacion->deposito_id, $s->ubicacion->orden, $s->ubicacion->codigo]);
        $sinUbicar = StockDeposito::with('deposito:id,nombre')->where('product_id', $p->id)->get()->map(fn($sd) => ['deposito' => $sd->deposito?->nombre, 'deposito_id' => $sd->deposito_id, 'cantidad' => $this->sinUbicar($p, $sd->deposito)])->filter(fn($x) => abs($x['cantidad']) > 0.0005)->values();
        return ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'unidad' => $p->unit, 'stock' => (float) $p->stock,
            'ubicaciones' => $ubic->map(fn($s) => ['ubicacion_id' => $s->ubicacion_id, 'codigo' => $s->ubicacion->codigo, 'deposito' => $s->ubicacion->deposito?->nombre, 'lote_id' => $s->lote_id, 'lote' => $s->lote?->etiqueta(), 'cantidad' => (float) $s->cantidad])->values(),
            'sin_ubicar' => $sinUbicar];
    }

    // Hoja de preparación de un pedido, factura o remito: de qué ubicación sacar cada cosa, ordenado por el recorrido.
    public function preparacion(Comprobante $c): array
    {
        $dep = Deposito::porDefecto($c->business_location_id);
        $pasos = []; $faltan = [];
        foreach ($c->items()->with('product')->get() as $it) {
            if (! $it->product || ! $it->product->controla_stock) continue;
            $resto = (float) $it->cantidad - (float) ($it->cantidad_entregada ?? 0);
            if ($resto <= 0.0005) continue;
            $filas = UbicacionStock::with('ubicacion', 'lote')->where('product_id', $it->product_id)->where('cantidad', '>', 0)->whereIn('ubicacion_id', Ubicacion::where('deposito_id', $dep?->id)->where('activa', true)->where('tipo', '!=', 'cuarentena')->select('id'))->get()
                ->sortBy(fn($s) => [$it->lote_id && $s->lote_id === $it->lote_id ? 0 : 1, $s->lote?->vencimiento?->timestamp ?? PHP_INT_MAX, $s->ubicacion->orden, $s->ubicacion->codigo]);
            foreach ($filas as $s) {
                if ($resto <= 0.0005) break;
                $usa = min((float) $s->cantidad, $resto);
                $pasos[] = ['orden' => $s->ubicacion->orden, 'codigo' => $s->ubicacion->codigo, 'ubicacion_id' => $s->ubicacion_id, 'product_id' => $it->product_id, 'nombre' => $it->product->name, 'sku' => $it->product->sku, 'barcode' => $it->product->barcode, 'unidad' => $it->product->unit, 'lote' => $s->lote?->etiqueta(), 'cantidad' => round($usa, 3)];
                $resto -= $usa;
            }
            if ($resto > 0.0005) $faltan[] = ['product_id' => $it->product_id, 'nombre' => $it->product->name, 'cantidad' => round($resto, 3), 'sin_ubicar' => $dep ? $this->sinUbicar($it->product, $dep) : 0];
        }
        usort($pasos, fn($a, $b) => [$a['orden'], $a['codigo']] <=> [$b['orden'], $b['codigo']]);
        return ['comprobante' => ['id' => $c->id, 'nombre' => $c->nombreTipo() . ' ' . $c->numeroFormateado(), 'cliente' => $c->contact?->name, 'fecha' => $c->fecha?->format('d/m/Y')], 'deposito' => $dep?->nombre, 'pasos' => $pasos, 'faltan' => $faltan];
    }

    // Alta masiva: pasillos (A..C o 1..3) × estantes × niveles, con código PASILLO-ESTANTE-NIVEL y orden de recorrido.
    public function generar(Deposito $d, array $pasillos, int $estantes, int $niveles, string $tipo = 'estanteria', string $prefijo = ''): int
    {
        $n = 0; $orden = (int) Ubicacion::where('deposito_id', $d->id)->max('orden');
        foreach ($pasillos as $pi => $pas) for ($e = 1; $e <= $estantes; $e++) {
            $es = str_pad((string) ($pi % 2 ? $estantes - $e + 1 : $e), 2, '0', STR_PAD_LEFT); // recorrido en serpentina: un pasillo de ida, el otro de vuelta
            for ($nv = 1; $nv <= max(1, $niveles); $nv++) {
                $codigo = Ubicacion::normalizar(($prefijo ? $prefijo . '-' : '') . $pas . '-' . $es . ($niveles > 0 ? '-' . $nv : ''));
                if (Ubicacion::where('codigo', $codigo)->exists()) continue;
                Ubicacion::create(['business_id' => $d->business_id, 'deposito_id' => $d->id, 'codigo' => $codigo, 'pasillo' => (string) $pas, 'estante' => $es, 'nivel' => $niveles > 0 ? (string) $nv : null, 'tipo' => $tipo, 'orden' => ++$orden]);
                $n++;
            }
        }
        return $n;
    }
}
