<?php

namespace App\Services\Pos;

use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Services\Comprobantes\CobroService;
use App\Services\Comprobantes\ComprobanteService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Venta de mostrador: arma la factura, la emite y cobra en el mismo paso. Reusa todo el circuito de comprobantes y fondos.
class PosService
{
    public function __construct(private ComprobanteService $comprobantes, private CobroService $cobros, private CuotasService $cuotas) {}

    // Cliente genérico para ventas sin identificar (se crea una vez por empresa).
    public function consumidorFinal(): Contact
    {
        return Contact::customers()->where('condicion_iva', 'Consumidor Final')->where('name', 'Consumidor Final')->first()
            ?? Contact::create(['business_id' => Auth::user()->business_id, 'type' => 'customer', 'name' => 'Consumidor Final', 'condicion_iva' => 'Consumidor Final', 'is_active' => true, 'lista_precios' => 1]);
    }

    // $d: items[{product_id, descripcion, cantidad, precio_unit, descuento, alicuota_iva}], contact_id?, medios[{medio, monto, cuenta_fondos_id?, referencia?}], descuento_global?, notas?
    public function vender(array $d): array
    {
        return DB::transaction(function () use ($d) {
            $contact = ! empty($d['contact_id']) ? Contact::customers()->findOrFail($d['contact_id']) : $this->consumidorFinal();
            $items = collect($d['items'])->filter(fn($i) => (float) $i['cantidad'] > 0)->values();
            if ($items->isEmpty()) throw ValidationException::withMessages(['items' => 'El ticket está vacío.']);
            // Tarjeta en cuotas: el recargo del plan entra como ítem para que la factura cierre con lo que paga el cliente.
            [$mediosCuotas, $recargos] = $this->cuotas->aplicar(Auth::user()->business, array_values(array_filter($d['medios'] ?? [], fn($m) => (float) $m['monto'] > 0)));
            $d['medios'] = $mediosCuotas;
            $items = $items->concat($recargos)->values();

            // En mostrador y salón los precios son finales (IVA incluido). El comprobante guarda el neto; en Factura C no hay IVA.
            $tipo = $this->comprobantes->resolverTipo($d['tipo'] ?? 'FX', $contact);
            $conIva = $d['precios_con_iva'] ?? true;
            $lineas = $items->map(function ($i) use ($tipo, $conIva) {
                $p = ! empty($i['product_id']) ? \App\Models\Product::find($i['product_id']) : null;
                $al = $tipo === 'FC' ? 0 : (float) ($i['alicuota_iva'] ?? $p?->iva ?? 21);
                $precio = (float) $i['precio_unit'];
                if ($conIva && $al > 0) $precio = round($precio / (1 + $al / 100), 4);
                return ['product_id' => $p?->id, 'descripcion' => $i['descripcion'] ?? null, 'cantidad' => $i['cantidad'], 'precio_unit' => $precio, 'descuento' => $i['descuento'] ?? 0, 'alicuota_iva' => $al, 'serie' => $i['serie'] ?? null];
            })->all();

            // Fase 32.1: el DNI (o nombre) del consumidor final va en la factura sin crear un cliente.
            $receptor = ! empty($d['receptor']['documento']) || ! empty($d['receptor']['nombre']) ? array_filter(['nombre' => $d['receptor']['nombre'] ?? $contact->name, 'documento' => $d['receptor']['documento'] ?? null]) : null;
            $c = $this->comprobantes->guardarBorrador([
                'contact_id' => $contact->id, 'tipo' => $tipo, 'fecha' => today()->toDateString(), 'condicion' => 'contado', 'notas' => $d['notas'] ?? null, 'items' => $lineas,
                'receptor_datos' => $receptor,
            ]);
            $c = $this->comprobantes->emitir($c);

            $medios = array_values(array_filter($d['medios'] ?? [], fn($m) => (float) $m['monto'] > 0));
            $pagado = round(array_sum(array_map(fn($m) => (float) $m['monto'], $medios)), 2);
            $total = (float) $c->total;
            $vuelto = 0;
            if ($pagado > $total + 0.005) {
                // El vuelto sale del efectivo.
                $vuelto = round($pagado - $total, 2);
                $ef = null;
                foreach ($medios as $k => $m) { if ($m['medio'] === 'efectivo') { $ef = $k; break; } }
                if ($ef === null) throw ValidationException::withMessages(['medios' => 'Lo pagado supera el total y no hay efectivo para dar vuelto.']);
                $medios[$ef]['monto'] = round((float) $medios[$ef]['monto'] - $vuelto, 2);
                $medios = array_values(array_filter($medios, fn($m) => (float) $m['monto'] > 0));
            }
            $medios = collect($medios);
            if ($pagado + 0.005 < $total && ! ($d['a_cuenta'] ?? false)) {
                throw ValidationException::withMessages(['medios' => 'Falta cobrar $ ' . number_format($total - $pagado, 2, ',', '.') . '. Si es a cuenta corriente, marcá "queda a cuenta".']);
            }

            $cobro = null;
            if ($medios->isNotEmpty()) {
                $cobro = $this->cobros->registrar($contact, ['fecha' => today()->toDateString(), 'notas' => 'Venta de mostrador', 'medios' => $medios->all(), 'imputaciones' => [['comprobante_id' => $c->id, 'monto' => min($total, round($medios->sum(fn($m) => (float) $m['monto']), 2))]]]);
            }
            AuditLog::registrar('pos', $c, "Venta de mostrador {$c->nombreTipo()} {$c->numeroFormateado()} $ " . number_format($total, 2, ',', '.'));
            return ['comprobante' => $c->fresh(), 'cobro' => $cobro, 'vuelto' => $vuelto];
        });
    }

    // ---- Fase 32.1 · Caja fluida ----

    // Las ventas de hoy de esta caja (de este usuario): para reimprimir, anular la última o devolver.
    public function ultimas(\App\Models\User $u, int $n = 12): array
    {
        return Comprobante::ventas()->with(['contact:id,name', 'imputaciones.cobro.medios', 'derivados:id,origen_id,tipo,estado,total'])->whereIn('modo_venta', ['mostrador', 'caja'])->where('user_id', $u->id)
            ->where('fecha', today()->toDateString())->whereIn('tipo', ['FA', 'FB', 'FC', 'FE'])->orderByDesc('id')->limit($n)->get()
            ->map(fn($c) => ['id' => $c->id, 'numero' => $c->numeroFormateado(), 'hora' => $c->emitido_en?->format('H:i') ?? $c->created_at->format('H:i'), 'cliente' => $c->contact?->name, 'total' => (float) $c->total, 'estado' => $c->estado,
                'medios' => $c->imputaciones->flatMap(fn($i) => $i->cobro?->medios ?? collect())->map(fn($m) => $m->medio)->unique()->values()->all(),
                'devuelto' => (float) $c->derivados->where('estado', 'emitido')->filter(fn($d) => str_starts_with($d->tipo, 'NC'))->sum('total'),
                'items' => $c->items->map(fn($i) => ['id' => $i->id, 'product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'precio_unit' => (float) $i->precio_unit, 'descuento' => (float) $i->descuento, 'alicuota_iva' => (float) $i->alicuota_iva, 'total' => (float) $i->total])->values()->all()])->values()->all();
    }

    // Anular la última venta: si todavía no es fiscal (interna o sin CAE) se anula y se devuelve el cobro; si ya tiene CAE, sale la nota de crédito
    // automática por el total y la plata vuelve por donde entró.
    public function anularVenta(Comprobante $c, \App\Models\User $u, string $motivo): array
    {
        return DB::transaction(function () use ($c, $u, $motivo) {
            abort_unless(in_array($c->modo_venta, ['mostrador', 'caja'], true) && $c->estado === 'emitido', 422, 'Sólo se anula una venta de mostrador emitida.');
            abort_unless($c->fecha->isToday(), 422, 'Una venta de otro día no se anula desde la caja: hacé la nota de crédito desde Comprobantes.');
            $cobros = $c->imputaciones->map(fn($i) => $i->cobro)->filter()->unique('id');
            if ($c->esFiscal() && $c->afip_estado === 'aprobado') {
                $medios = $cobros->flatMap(fn($co) => $co->medios);
                $nc = $this->devolver($c, $c->items->map(fn($i) => ['item_id' => $i->id, 'cantidad' => (float) $i->cantidad])->all(), $u, $medios->first()?->medio ?? 'efectivo', $medios->first()?->cuenta_fondos_id, "Anulación de la venta: {$motivo}");
                return ['modo' => 'nc', 'comprobante' => $nc];
            }
            foreach ($cobros as $co) $this->cobros->anular($co, "Venta anulada: {$motivo}");
            $this->comprobantes->anular($c, $motivo);
            return ['modo' => 'anulada', 'comprobante' => $c->fresh()];
        });
    }

    // Devolución desde la caja: nota de crédito por lo que vuelve y la plata por el medio elegido (efectivo de la caja, transferencia, etc.).
    // Con tarjeta no sale plata: queda el cupón a anular con la procesadora, y se anota.
    public function devolver(Comprobante $c, array $items, \App\Models\User $u, string $medio = 'efectivo', ?int $cuentaId = null, ?string $motivo = null): Comprobante
    {
        return DB::transaction(function () use ($c, $items, $u, $medio, $cuentaId, $motivo) {
            abort_unless($c->estado === 'emitido' && $c->esFactura(), 422, 'Sólo se devuelve sobre una factura emitida.');
            $porId = $c->items->keyBy('id');
            $yaDevuelto = [];
            foreach ($c->derivados()->where('estado', 'emitido')->where('tipo', 'like', 'NC%')->with('items')->get() as $d) foreach ($d->items as $di) $yaDevuelto[$di->origen_item_id] = ($yaDevuelto[$di->origen_item_id] ?? 0) + (float) $di->cantidad;
            $lineas = [];
            foreach ($items as $i) {
                $it = $porId[$i['item_id']] ?? null; $cant = (float) ($i['cantidad'] ?? 0);
                if (! $it || $cant <= 0) continue;
                $max = (float) $it->cantidad - ($yaDevuelto[$it->id] ?? 0);
                if ($cant > $max + 0.0005) throw ValidationException::withMessages(['items' => "De {$it->descripcion} se vendieron " . rtrim(rtrim(number_format((float) $it->cantidad, 3, ',', '.'), '0'), ',') . ' y ya volvieron ' . rtrim(rtrim(number_format($yaDevuelto[$it->id] ?? 0, 3, ',', '.'), '0'), ',') . '.']);
                $lineas[] = ['product_id' => $it->product_id, 'descripcion' => $it->descripcion, 'cantidad' => $cant, 'unidad' => $it->unidad, 'precio_unit' => $it->precio_unit, 'descuento' => $it->descuento, 'alicuota_iva' => $it->alicuota_iva, 'origen_item_id' => $it->id];
            }
            if (! $lineas) throw ValidationException::withMessages(['items' => 'Elegí qué vuelve.']);
            $tipoNc = 'NC' . substr($c->tipo, 1, 1);
            $nc = $this->comprobantes->guardarBorrador(['contact_id' => $c->contact_id, 'tipo' => $tipoNc, 'origen_id' => $c->id, 'fecha' => today()->toDateString(), 'condicion' => 'contado', 'items' => $lineas,
                'notas' => trim(($motivo ?: 'Devolución en caja') . " · sobre {$c->nombreTipo()} {$c->numeroFormateado()} · plata devuelta por " . (\App\Models\Cobro::MEDIOS[$medio] ?? $medio)), 'receptor_datos' => $c->receptor ?: null]);
            $nc = $this->comprobantes->emitir($nc);
            $nc->forceFill(['modo_venta' => $c->modo_venta])->saveQuietly();
            // La plata vuelve: egreso del fondo y el asiento en la cuenta del cliente para que no quede como crédito a favor.
            $monto = (float) $nc->total;
            if ($medio !== 'tarjeta') {
                $cuenta = $this->fondosSvc()->cuentaPara($medio, $u, $cuentaId);
                if ($cuenta) $this->fondosSvc()->registrar($cuenta, ['fecha' => today(), 'origen' => 'devolucion', 'origen_id' => $nc->id, 'concepto' => "Devolución {$nc->nombreTipo()} {$nc->numeroFormateado()} · {$c->contact?->name}", 'egreso' => $monto]);
            }
            \App\Models\CuentaCorriente::create(['business_id' => $c->business_id, 'contact_id' => $c->contact_id, 'comprobante_id' => $nc->id, 'fecha' => today()->toDateString(), 'tipo' => 'devolucion', 'concepto' => "Plata devuelta por " . (\App\Models\Cobro::MEDIOS[$medio] ?? $medio) . " · {$nc->numeroFormateado()}", 'debe' => $monto, 'haber' => 0]);
            \App\Models\CuentaCorriente::recalcularSaldo($c->contact_id);
            AuditLog::registrar('devolucion_caja', $nc, "{$u->name}: devolución de " . number_format($monto, 2, ',', '.') . " sobre {$c->numeroFormateado()} por " . (\App\Models\Cobro::MEDIOS[$medio] ?? $medio));
            return $nc->fresh();
        });
    }

    private function fondosSvc(): \App\Services\Fondos\FondosService { return app(\App\Services\Fondos\FondosService::class); }

    // Los más vendidos de los últimos 30 días en esta caja: botones grandes para lo que no tiene código.
    public function frecuentes(int $businessId, int $n = 12): array
    {
        return DB::table('comprobante_items')->join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')
            ->where('comprobantes.business_id', $businessId)->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')->whereIn('comprobantes.modo_venta', ['mostrador', 'caja'])
            ->where('comprobantes.fecha', '>=', today()->subDays(30)->toDateString())->whereNotNull('comprobante_items.product_id')
            ->groupBy('comprobante_items.product_id')->orderByRaw('COUNT(*) DESC')->orderBy('comprobante_items.product_id')->limit($n)->pluck('comprobante_items.product_id')->map(fn($x) => (int) $x)->all();
    }
}
