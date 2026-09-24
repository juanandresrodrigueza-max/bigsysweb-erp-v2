<?php

namespace App\Services\Comprobantes;

use App\Models\Acopio;
use App\Models\AcopioItem;
use App\Models\AcopioRetiro;
use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcopioService
{
    public function __construct(private ComprobanteService $comprobantes) {}

    // Retiro parcial: genera remito, descuenta stock y actualiza pendiente. El precio ya está congelado.
    public function retirar(Acopio $acopio, array $data): AcopioRetiro
    {
        return DB::transaction(function () use ($acopio, $data) {
            abort_if(in_array($acopio->estado, ['cerrado'], true), 422, 'El acopio ya está cerrado.');
            $lineas = collect($data['items'])->filter(fn($i) => (float) $i['cantidad'] > 0)->values();
            if ($lineas->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Indicá qué cantidades se retiran.']);
            }

            $items = [];
            foreach ($lineas as $l) {
                $ai = AcopioItem::lockForUpdate()->findOrFail($l['acopio_item_id']);
                abort_if($ai->acopio_id !== $acopio->id, 422, 'Ítem inválido.');
                if ((float) $l['cantidad'] > $ai->pendiente() + 0.0005) {
                    throw ValidationException::withMessages(['items' => "{$ai->descripcion}: quedan {$ai->pendiente()} por retirar."]);
                }
                $items[] = [$ai, (float) $l['cantidad']];
            }

            $remito = $this->comprobantes->guardarBorrador([
                'contact_id' => $acopio->contact_id, 'tipo' => 'REM', 'origen_id' => $acopio->comprobante_id, 'fecha' => $data['fecha'] ?? today()->toDateString(),
                'condicion' => 'contado', 'notas' => 'Retiro de acopio ' . ($data['retirado_por'] ? "por {$data['retirado_por']}" : ''),
                'items' => collect($items)->map(fn($x) => ['product_id' => $x[0]->product_id, 'descripcion' => $x[0]->descripcion, 'cantidad' => $x[1], 'precio_unit' => $x[0]->precio_congelado, 'alicuota_iva' => 0])->all(),
            ]);
            // El remito de acopio siempre mueve stock (la factura no lo movió).
            $remito->forceFill(['origen_id' => null])->save();
            $remito = $this->comprobantes->emitir($remito);
            $remito->forceFill(['origen_id' => $acopio->comprobante_id])->save();

            $retiro = AcopioRetiro::create(['acopio_id' => $acopio->id, 'remito_id' => $remito->id, 'user_id' => Auth::id(), 'fecha' => $data['fecha'] ?? today(), 'retirado_por' => $data['retirado_por'] ?? null, 'observaciones' => $data['observaciones'] ?? null]);
            foreach ($items as [$ai, $cant]) {
                $retiro->items()->create(['acopio_item_id' => $ai->id, 'cantidad' => $cant]);
                $ai->increment('cantidad_retirada', $cant);
            }
            $acopio->actualizarEstado();
            AuditLog::registrar('crear', $retiro, "Retiro de acopio #{$acopio->id}, remito {$remito->numeroFormateado()}");
            return $retiro->fresh('items');
        });
    }
}
