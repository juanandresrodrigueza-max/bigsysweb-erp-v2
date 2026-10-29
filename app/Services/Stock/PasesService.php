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
    public const ESTADOS = ['en_viaje' => 'En viaje', 'recibida' => 'Recibido completo', 'con_diferencias' => 'Recibido con diferencias', 'confirmada' => 'Transferencia directa', 'anulada' => 'Anulado'];

    public function __construct(private StockService $stock) {}

    // Leer lo que se escanea: código, barras, etiqueta de balanza o "3*código".
    public function leer(string $codigo): array
    {
        $r = app(ConsultorService::class)->buscar($codigo, Auth::user());
        if (! $r['exacto'] || ! ($r['items'][0] ?? null)) return ['ok' => false, 'mensaje' => "No encuentro el código {$codigo}."];
        $f = $r['items'][0];
        return ['ok' => true, 'product_id' => $f['id'], 'nombre' => $f['nombre'], 'unidad' => $f['unit'] ?? null, 'cantidad' => (float) $r['cantidad'], 'pesable' => (bool) ($f['pesable'] ?? false)];
    }

    public function enviar(Deposito $origen, Deposito $destino, array $items, ?string $notas = null): TransferenciaStock
    {
        abort_if($origen->id === $destino->id, 422, 'El origen y el destino son el mismo depósito.');
        $items = collect($items)->groupBy('product_id')->map(fn($g, $pid) => ['product_id' => (int) $pid, 'cantidad' => round($g->sum(fn($x) => (float) $x['cantidad']), 3)])->filter(fn($x) => $x['cantidad'] > 0)->values();
        if ($items->isEmpty()) throw ValidationException::withMessages(['items' => 'El pase no tiene artículos.']);
        return DB::transaction(function () use ($origen, $destino, $items, $notas) {
            $u = Auth::user();
            $t = TransferenciaStock::create(['business_id' => $u->business_id, 'business_location_id' => $u->current_location_id, 'user_id' => $u->id, 'numero' => (TransferenciaStock::max('numero') ?? 0) + 1, 'fecha' => today(), 'origen_id' => $origen->id, 'destino_id' => $destino->id, 'notas' => $notas, 'estado' => 'en_viaje', 'enviada_en' => now(), 'token' => Str::random(40)]);
            foreach ($items as $it) {
                $p = Product::findOrFail($it['product_id']);
                if ($p->controla_stock && $p->stockEn($origen->id) + 0.0005 < $it['cantidad']) throw ValidationException::withMessages(['items' => "No hay {$it['cantidad']} de {$p->name} en {$origen->nombre} (hay " . $p->stockEn($origen->id) . ').']);
                $t->items()->create(['product_id' => $p->id, 'cantidad' => $it['cantidad']]);
                $this->stock->mover($p, -$it['cantidad'], $origen, 'transferencia', "{$t->numeroFormateado()} en viaje a {$destino->nombre}", $t);
            }
            AuditLog::registrar('crear', $t, "Pase {$t->numeroFormateado()} enviado: {$origen->nombre} → {$destino->nombre}, " . $items->count() . ' artículos');
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

    public function resumen(TransferenciaStock $t): array
    {
        $t->loadMissing('items.product', 'origen', 'destino', 'user');
        return ['id' => $t->id, 'numero' => $t->numeroFormateado(), 'fecha' => $t->fecha->format('d/m/Y'), 'origen' => $t->origen?->nombre, 'destino' => $t->destino?->nombre, 'origen_id' => $t->origen_id, 'destino_id' => $t->destino_id, 'estado' => $t->estado, 'estado_label' => self::ESTADOS[$t->estado] ?? $t->estado, 'usuario' => $t->user?->name, 'enviada' => $t->enviada_en?->format('d/m H:i'), 'recibida' => $t->recibida_en?->format('d/m H:i'), 'notas' => $t->notas, 'token' => $t->token,
            'items' => $t->items->map(fn($i) => ['id' => $i->id, 'articulo' => $i->product?->name, 'codigo' => $i->product?->barcode ?: $i->product?->sku, 'unidad' => $i->product?->unit, 'enviado' => (float) $i->cantidad, 'recibido' => $i->cantidad_recibida !== null ? (float) $i->cantidad_recibida : null, 'diferencia' => $i->cantidad_recibida !== null ? round((float) $i->cantidad_recibida - (float) $i->cantidad, 3) : null])->values()->all()];
    }
}
