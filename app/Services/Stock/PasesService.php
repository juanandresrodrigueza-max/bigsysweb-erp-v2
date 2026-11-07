<?php

namespace App\Services\Stock;

use App\Models\AuditLog;
use App\Models\Deposito;
use App\Models\Product;
use App\Models\TransferenciaStock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Fase 37.4: el pase entre sucursales se cuenta dos veces. Se arma escaneando (con multiplicador y etiquetas de balanza), al enviar sale
// el stock del origen y queda "en viaje"; la otra sucursal lo recibe contando y entra lo que llegó. Las diferencias las ven las dos.
class PasesService
{
    public const ESTADOS = ['pedido' => 'Pedido, sin armar', 'armado' => 'Pedido armado', 'en_proveedor' => 'En el proveedor', 'vuelto' => 'Volvió del proveedor', 'cerrado' => 'Cerrado sin volver', 'en_viaje' => 'En viaje', 'recibida' => 'Recibido completo', 'con_diferencias' => 'Recibido con diferencias', 'confirmada' => 'Transferencia directa', 'anulada' => 'Anulado'];

    public function __construct(private StockService $stock) {}

    // Leer lo que se escanea: código, barras, etiqueta de balanza o "3*código".
    public function leer(string $codigo): array
    {
        $r = app(ConsultorService::class)->buscar($codigo, Auth::user());
        if (! $r['exacto'] || ! ($r['items'][0] ?? null)) return ['ok' => false, 'mensaje' => "No encuentro el código {$codigo}."];
        $f = $r['items'][0];
        return ['ok' => true, 'product_id' => $f['id'], 'nombre' => $f['nombre'], 'unidad' => $f['unit'] ?? null, 'cantidad' => (float) $r['cantidad'], 'pesable' => (bool) ($f['pesable'] ?? false)];
    }

    public function enviar(Deposito $origen, Deposito $destino, array $items, ?string $notas = null, ?TransferenciaStock $pedido = null): TransferenciaStock
    {
        abort_if($pedido && $pedido->estado !== 'pedido', 422, 'Ese pedido ya se armó.');
        abort_if($origen->id === $destino->id, 422, 'El origen y el destino son el mismo depósito.');
        $items = collect($items)->groupBy('product_id')->map(fn($g, $pid) => ['product_id' => (int) $pid, 'cantidad' => round($g->sum(fn($x) => (float) $x['cantidad']), 3)])->filter(fn($x) => $x['cantidad'] > 0)->values();
        if ($items->isEmpty()) throw ValidationException::withMessages(['items' => 'El pase no tiene artículos.']);
        return DB::transaction(function () use ($origen, $destino, $items, $notas, $pedido) {
            $u = Auth::user();
            $t = TransferenciaStock::create(['business_id' => $u->business_id, 'business_location_id' => $u->current_location_id, 'user_id' => $u->id, 'numero' => (TransferenciaStock::max('numero') ?? 0) + 1, 'fecha' => today(), 'origen_id' => $origen->id, 'destino_id' => $destino->id, 'notas' => $notas, 'estado' => 'en_viaje', 'enviada_en' => now(), 'token' => Str::random(40)]);
            foreach ($items as $it) {
                $p = Product::findOrFail($it['product_id']);
                if ($p->controla_stock && $p->stockEn($origen->id) + 0.0005 < $it['cantidad']) throw ValidationException::withMessages(['items' => "No hay {$it['cantidad']} de {$p->name} en {$origen->nombre} (hay " . $p->stockEn($origen->id) . ').']);
                $t->items()->create(['product_id' => $p->id, 'cantidad' => $it['cantidad']]);
                $this->stock->mover($p, -$it['cantidad'], $origen, 'transferencia', "{$t->numeroFormateado()} en viaje a {$destino->nombre}", $t);
            }
            if ($pedido) {
                $t->update(['pedido_id' => $pedido->id]);
                // Lo pedido queda al lado de lo enviado, para ver qué no se mandó.
                foreach ($pedido->items as $pi) $t->items()->where('product_id', $pi->product_id)->update(['cantidad_pedida' => $pi->cantidad]);
                $pedido->update(['estado' => 'armado']);
            }
            AuditLog::registrar('crear', $t, "Pase {$t->numeroFormateado()} enviado: {$origen->nombre} → {$destino->nombre}, " . $items->count() . ' artículos' . ($pedido ? " (pedido {$pedido->numeroFormateado()})" : ''));
            return $t->fresh('items');
        });
    }

    // Recibir contando: entra lo que llegó. Lo que falta o sobra queda a la vista de las dos sucursales.
    public function recibir(TransferenciaStock $t, array $conteos, ?string $notas = null): TransferenciaStock
    {
        abort_if($t->estado !== 'en_viaje', 422, 'Este pase no está en viaje.');
        return DB::transaction(function () use ($t, $conteos, $notas) {
            $dif = 0;
            foreach ($t->items()->with('product')->get() as $it) {
                $rec = round((float) ($conteos[$it->id] ?? $conteos[(string) $it->id] ?? 0), 3);
                $it->update(['cantidad_recibida' => $rec]);
                if ($rec > 0) $this->stock->mover($it->product, $rec, $t->destino, 'transferencia', "{$t->numeroFormateado()} recibido de {$t->origen->nombre}", $t);
                if (abs($rec - (float) $it->cantidad) > 0.0005) $dif++;
            }
            $t->update(['estado' => $dif ? 'con_diferencias' : 'recibida', 'recibida_en' => now(), 'recibida_por' => Auth::id(), 'notas' => trim(($t->notas ?? '') . ($notas ? "\nAl recibir: {$notas}" : ''))]);
            AuditLog::registrar('editar', $t, "Recibió el pase {$t->numeroFormateado()}" . ($dif ? " con {$dif} diferencias" : ' completo'));
            return $t->fresh('items');
        });
    }

    // Lo que no llegó vuelve al origen (se perdió en el camino o quedó en el camión): se decide después de mirar las diferencias.
    public function devolverFaltante(TransferenciaStock $t): int
    {
        abort_if($t->estado !== 'con_diferencias', 422, 'No hay diferencias para resolver.');
        $n = 0;
        DB::transaction(function () use ($t, &$n) {
            foreach ($t->items()->with('product')->get() as $it) { $falta = round((float) $it->cantidad - (float) $it->cantidad_recibida, 3); if ($falta > 0) { $this->stock->mover($it->product, $falta, $t->origen, 'transferencia', "{$t->numeroFormateado()}: vuelve lo que no llegó", $t); $n++; } }
            $t->update(['notas' => trim(($t->notas ?? '') . "\nLo que faltó volvió al origen."), 'estado' => 'recibida']);
            AuditLog::registrar('editar', $t, "Pase {$t->numeroFormateado()}: {$n} faltantes vuelven a {$t->origen->nombre}");
        });
        return $n;
    }

    // Fase 42.1: la sucursal le pide al depósito escaneando. No mueve stock: del pedido, el depósito arma el pase.
    public function pedir(Deposito $deposito, Deposito $sucursal, array $items, ?string $notas = null): TransferenciaStock
    {
        abort_if($deposito->id === $sucursal->id, 422, 'El depósito y la sucursal que pide son el mismo.');
        $items = collect($items)->groupBy('product_id')->map(fn($g, $pid) => ['product_id' => (int) $pid, 'cantidad' => round($g->sum(fn($x) => (float) $x['cantidad']), 3)])->filter(fn($x) => $x['cantidad'] > 0)->values();
        if ($items->isEmpty()) throw ValidationException::withMessages(['items' => 'El pedido no tiene artículos.']);
        return DB::transaction(function () use ($deposito, $sucursal, $items, $notas) {
            $u = Auth::user();
            $t = TransferenciaStock::create(['business_id' => $u->business_id, 'business_location_id' => $u->current_location_id, 'user_id' => $u->id, 'numero' => (TransferenciaStock::max('numero') ?? 0) + 1, 'fecha' => today(), 'origen_id' => $deposito->id, 'destino_id' => $sucursal->id, 'notas' => $notas, 'estado' => 'pedido', 'tipo' => 'pedido', 'token' => Str::random(40)]);
            foreach ($items as $it) $t->items()->create(['product_id' => Product::findOrFail($it['product_id'])->id, 'cantidad' => $it['cantidad'], 'cantidad_pedida' => $it['cantidad']]);
            AuditLog::registrar('crear', $t, "Pedido {$t->numeroFormateado()}: {$sucursal->nombre} le pide a {$deposito->nombre} " . $items->count() . ' artículos');
            return $t->fresh('items');
        });
    }

    // Fase 42.2: cambio o devolución a un proveedor. Sale del stock a la «cuenta de cambios» con el proveedor en el remito;
    // cuando vuelve (el mismo artículo o el cambio) se recibe contando y entra al mismo depósito.
    public function aProveedor(Deposito $deposito, \App\Models\Contact $proveedor, array $items, ?string $notas = null): TransferenciaStock
    {
        $items = collect($items)->groupBy('product_id')->map(fn($g, $pid) => ['product_id' => (int) $pid, 'cantidad' => round($g->sum(fn($x) => (float) $x['cantidad']), 3)])->filter(fn($x) => $x['cantidad'] > 0)->values();
        if ($items->isEmpty()) throw ValidationException::withMessages(['items' => 'No hay artículos para mandar.']);
        return DB::transaction(function () use ($deposito, $proveedor, $items, $notas) {
            $u = Auth::user();
            $t = TransferenciaStock::create(['business_id' => $u->business_id, 'business_location_id' => $u->current_location_id, 'user_id' => $u->id, 'numero' => (TransferenciaStock::max('numero') ?? 0) + 1, 'fecha' => today(), 'origen_id' => $deposito->id, 'destino_id' => $deposito->id, 'contact_id' => $proveedor->id, 'notas' => $notas, 'estado' => 'en_proveedor', 'tipo' => 'proveedor', 'enviada_en' => now(), 'token' => Str::random(40)]);
            foreach ($items as $it) {
                $p = Product::findOrFail($it['product_id']);
                $t->items()->create(['product_id' => $p->id, 'cantidad' => $it['cantidad']]);
                $this->stock->mover($p, -$it['cantidad'], $deposito, 'transferencia', "{$t->numeroFormateado()} a {$proveedor->name} (cambios)", $t);
            }
            AuditLog::registrar('crear', $t, "Devolución {$t->numeroFormateado()} a {$proveedor->name}: " . $items->count() . ' artículos');
            return $t->fresh('items');
        });
    }

    // Vuelve del proveedor: entra lo que se cuenta. Lo que no vuelve se cierra (lo reconoce con una nota de crédito o se pierde).
    public function volvioDelProveedor(TransferenciaStock $t, array $conteos, bool $cerrar = false, ?string $notas = null): TransferenciaStock
    {
        abort_if($t->tipo !== 'proveedor' || $t->estado !== 'en_proveedor', 422, 'Esto no está en el proveedor.');
        return DB::transaction(function () use ($t, $conteos, $cerrar, $notas) {
            $vuelve = 0;
            foreach ($t->items()->with('product')->get() as $it) {
                $rec = $cerrar ? 0 : round((float) ($conteos[$it->id] ?? $conteos[(string) $it->id] ?? 0), 3);
                $it->update(['cantidad_recibida' => $rec]);
                if ($rec > 0) { $this->stock->mover($it->product, $rec, $t->origen, 'transferencia', "{$t->numeroFormateado()} volvió de {$t->contacto?->name}", $t); $vuelve++; }
            }
            $t->update(['estado' => $cerrar ? 'cerrado' : 'vuelto', 'recibida_en' => now(), 'recibida_por' => Auth::id(), 'notas' => trim(($t->notas ?? '') . ($notas ? "\n{$notas}" : ''))]);
            AuditLog::registrar('editar', $t, $cerrar ? "Cerró {$t->numeroFormateado()} sin que vuelva la mercadería" : "Volvió del proveedor {$t->numeroFormateado()} ({$vuelve} artículos)");
            return $t->fresh('items');
        });
    }

    // Fase 42.1: filtros de la lista (estado, tipo, fecha, sucursal y buscador por número o artículo).
    public function lista(array $f): \Illuminate\Support\Collection
    {
        $q = TransferenciaStock::with('items.product', 'origen', 'destino', 'user', 'contacto')->latest('id');
        if (! empty($f['tipo'])) $q->where('tipo', $f['tipo']);
        if (! empty($f['estado'])) $q->where('estado', $f['estado']);
        if (! empty($f['deposito'])) $q->where(fn($w) => $w->where('origen_id', $f['deposito'])->orWhere('destino_id', $f['deposito']));
        if (! empty($f['desde'])) $q->whereDate('fecha', '>=', $f['desde']);
        if (! empty($f['hasta'])) $q->whereDate('fecha', '<=', $f['hasta']);
        if (! empty($f['q'])) { $txt = trim($f['q']); $q->where(fn($w) => $w->where('numero', (int) preg_replace('/\D/', '', $txt) ?: -1)->orWhereHas('items.product', fn($p) => $p->where('name', \App\Support\Sql::like(), "%{$txt}%")->orWhere('sku', $txt)->orWhere('barcode', $txt))); }
        return $q->limit(60)->get()->map(fn($t) => $this->resumen($t));
    }

    // Fase 42.8: pases enviados que nadie confirmó que llegaron (más de 2 días en viaje).
    public static function enViajeSinConfirmar(int $businessId, int $dias = 2): \Illuminate\Support\Collection
    {
        return TransferenciaStock::withoutGlobalScopes()->where('business_id', $businessId)->where('estado', 'en_viaje')->where('enviada_en', '<', now()->subDays($dias))->get();
    }

    public function resumen(TransferenciaStock $t): array
    {
        $t->loadMissing('items.product', 'origen', 'destino', 'user', 'contacto');
        return ['tipo' => $t->tipo ?? 'pase', 'proveedor' => $t->contacto?->name, 'pedido_id' => $t->pedido_id, 'id' => $t->id, 'numero' => $t->numeroFormateado(), 'fecha' => $t->fecha->format('d/m/Y'), 'origen' => $t->origen?->nombre, 'destino' => $t->destino?->nombre, 'origen_id' => $t->origen_id, 'destino_id' => $t->destino_id, 'estado' => $t->estado, 'estado_label' => self::ESTADOS[$t->estado] ?? $t->estado, 'usuario' => $t->user?->name, 'enviada' => $t->enviada_en?->format('d/m H:i'), 'recibida' => $t->recibida_en?->format('d/m H:i'), 'notas' => $t->notas, 'token' => $t->token,
            'items' => $t->items->map(fn($i) => ['id' => $i->id, 'product_id' => $i->product_id, 'articulo' => $i->product?->name, 'codigo' => $i->product?->barcode ?: $i->product?->sku, 'unidad' => $i->product?->unit, 'enviado' => (float) $i->cantidad, 'pedido' => $i->cantidad_pedida !== null ? (float) $i->cantidad_pedida : null, 'recibido' => $i->cantidad_recibida !== null ? (float) $i->cantidad_recibida : null, 'diferencia' => $i->cantidad_recibida !== null ? round((float) $i->cantidad_recibida - (float) $i->cantidad, 3) : null])->values()->all()];
    }
}
