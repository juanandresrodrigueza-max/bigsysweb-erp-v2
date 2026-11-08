<?php

namespace App\Services\Compras;

use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Contact;
use App\Models\CuentaCorriente;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Facturas de compra: mismo modelo Comprobante con direccion=compra; el número lo pone el proveedor.
class CompraService
{
    public function __construct(private \App\Services\Stock\StockService $stock) {}

    public function guardarBorrador(array $data, ?Comprobante $c = null): Comprobante
    {
        return DB::transaction(function () use ($data, $c) {
            $user = Auth::user();
            $proveedor = Contact::suppliers()->findOrFail($data['contact_id']);

            $c ??= new Comprobante(['business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'user_id' => $user->id, 'direccion' => 'compra', 'afip_estado' => 'no_aplica']);
            abort_if($c->exists && $c->estado !== 'borrador', 422, 'Solo se editan borradores.');

            $numero = preg_replace('/\s+/', '', $data['numero_proveedor'] ?? '');
            if ($numero && ! preg_match('/^\d{1,5}-\d{1,8}$/', $numero)) {
                throw ValidationException::withMessages(['numero_proveedor' => 'Formato esperado: punto de venta-número, por ejemplo 0003-00012345.']);
            }
            if ($numero) {
                [$pv, $num] = explode('-', $numero);
                $numero = sprintf('%04d-%08d', (int) $pv, (int) $num);
                $dup = Comprobante::compras()->where('contact_id', $proveedor->id)->where('tipo', $data['tipo'])->where('numero_proveedor', $numero)->where('id', '!=', $c->id ?? 0)->exists();
                if ($dup) {
                    throw ValidationException::withMessages(['numero_proveedor' => "Ya cargaste {$data['tipo']} {$numero} de este proveedor."]);
                }
            }

            $dias = (int) ($data['dias_vto'] ?? $proveedor->dias_pago ?? 0);
            $c->fill([
                'contact_id' => $proveedor->id, 'tipo' => $data['tipo'], 'numero_proveedor' => $numero ?: null, 'cae_proveedor' => $data['cae_proveedor'] ?? null,
                'solo_stock' => (bool) ($data['solo_stock'] ?? ($c->exists ? $c->solo_stock : false)), 'solo_contable' => (bool) ($data['solo_contable'] ?? ($c->exists ? $c->solo_contable : false)), 'remito_id' => $data['remito_id'] ?? $c->remito_id,
                'origen_carga' => $data['origen_carga'] ?? 'manual', 'origen_id' => $data['origen_id'] ?? $c->origen_id, 'orden_compra_id' => $data['orden_compra_id'] ?? ($data['ordenes_compra'][0] ?? null) ?? $c->orden_compra_id, 'ordenes_compra' => $data['ordenes_compra'] ?? $c->ordenes_compra,
                'stock_en_business_id' => array_key_exists('stock_en_business_id', $data) ? $this->empresaStockValida($data['stock_en_business_id']) : $c->stock_en_business_id,
                'fecha' => $data['fecha'], 'fecha_vto' => isset($data['fecha_vto']) && $data['fecha_vto'] ? $data['fecha_vto'] : \Carbon\Carbon::parse($data['fecha'])->addDays($dias),
                'condicion' => $data['condicion'] ?? 'cta_cte', 'notas' => $data['notas'] ?? null,
                'moneda' => $moneda = strtoupper($data['moneda'] ?? 'ARS'),
                'cotizacion' => $cot = ($moneda === 'ARS' ? 1 : (float) (($data['cotizacion'] ?? null) ?: \App\Models\Cotizacion::valor($user->business_id))),
            ])->save();
            abort_if($moneda !== 'ARS' && $cot <= 0, 422, 'Cargá la cotización del dólar para registrar una compra en moneda extranjera.');

            $c->items()->delete();
            foreach (array_values($data['items']) as $i => $it) {
                $product = ! empty($it['product_id']) ? Product::find($it['product_id']) : null;
                $al = (float) ($it['alicuota_iva'] ?? 21);
                // Compra en dólares: los precios vienen en USD y se guardan en pesos a la cotización de la factura.
                if ($moneda !== 'ARS') $it['precio_unit'] = round((float) $it['precio_unit'] * $cot, 2);
                $calc = ComprobanteItem::calcular((float) $it['cantidad'], (float) $it['precio_unit'], (float) ($it['descuento'] ?? 0), $al);
                $c->items()->create(['product_id' => $product?->id, 'descripcion' => ($it['descripcion'] ?? null) ?: ($product?->name ?? 'Ítem'), 'cantidad' => $it['cantidad'], 'unidad' => $it['unidad'] ?? $product?->unit, 'precio_unit' => $it['precio_unit'], 'descuento' => $it['descuento'] ?? 0, 'alicuota_iva' => $al, 'orden' => $i, 'lote' => $it['lote'] ?? null, 'vencimiento' => $it['vencimiento'] ?? null, 'serie' => $it['serie'] ?? null, 'actualiza_costo' => (bool) ($it['actualiza_costo'] ?? true), ...$calc]);
            }

            $c->impuestos()->delete();
            foreach ($data['impuestos'] ?? [] as $imp) {
                if ((float) ($imp['monto'] ?? 0) > 0) {
                    $c->impuestos()->create(['tipo' => $imp['tipo'], 'base' => $imp['base'] ?? 0, 'alicuota' => $imp['alicuota'] ?? 0, 'monto' => $imp['monto']]);
                }
            }
            $c->recalcularTotales();
            // Percepciones/otros impuestos del proveedor suman al total (recalcularTotales solo toma iibb*)
            $otros = (float) $c->impuestos()->where('tipo', 'not like', 'iibb%')->sum('monto');
            if ($otros > 0) {
                $c->forceFill(['percepciones' => (float) $c->percepciones + $otros, 'total' => round((float) $c->total + $otros, 2), 'saldo' => $c->estado === 'emitido' ? round((float) $c->saldo + $otros, 2) : round((float) $c->total + $otros, 2)])->save();
            }
            if ($moneda !== 'ARS') $c->forceFill(['total_me' => round((float) $c->fresh()->total / $cot, 2), 'neto_me' => round((float) $c->fresh()->neto / $cot, 2)])->save();
            return $c->fresh(['items', 'contact']);
        });
    }

    // Registrar = confirmar la factura de compra: cuenta corriente del proveedor, stock y costo.
    public function registrar(Comprobante $c): Comprobante
    {
        return DB::transaction(function () use ($c) {
            abort_if($c->direccion !== 'compra', 422, 'No es una compra.');
            abort_if($c->estado !== 'borrador', 422, 'Ya fue registrada.');
            abort_if($c->items()->count() === 0, 422, 'La compra no tiene ítems.');

            // Fase 31.5: si el negocio exige el control con la pistola, una compra con mercadería no se registra sin contarla.
            $rs = app(RecepcionService::class);
            if (RecepcionService::controlable($c) && ($rs->config($c->business)['exigir_control'] ?? false) && ! \App\Models\Recepcion::where('comprobante_id', $c->id)->where('estado', 'cerrada')->exists()) {
                abort(422, 'Esta compra tiene que controlarse con la pistola antes de registrarse (Controlar recepción).');
            }
            $c->forceFill(['estado' => 'emitido', 'emitido_en' => now(), 'saldo' => $c->def()['cc'] > 0 ? $c->total : 0])->save();

            $signo = $c->def()['cc'];
            // Remito del proveedor sin precios: sólo stock. La factura de ese remito: sólo contabilidad y cuenta.
            if ($signo !== 0 && ! $c->solo_stock) {
                CuentaCorriente::create([
                    'business_id' => $c->business_id, 'contact_id' => $c->contact_id, 'comprobante_id' => $c->id, 'fecha' => $c->fecha,
                    'fecha_vto' => $signo > 0 ? $c->fecha_vto : null, 'tipo' => $c->def()['grupo'], 'concepto' => "{$c->nombreTipo()} {$c->numeroFormateado()} (compra)",
                    'debe' => $signo > 0 ? $c->total : 0, 'haber' => $signo < 0 ? $c->total : 0,
                ]);
                if ($signo < 0 && $c->origen_id) {
                    $origen = $c->origen;
                    $origen->decrement('saldo', min((float) $origen->saldo, (float) $c->total));
                }
                CuentaCorriente::recalcularSaldo($c->contact_id);
            }

            // Fase 42.6: la mercadería entra en otra empresa del grupo. La factura queda acá, sólo contable, y el costo se actualiza allá.
            if ($c->def()['stock'] && ! $c->solo_contable && $c->stock_en_business_id) {
                $this->entrarEnOtraEmpresa($c);
            } elseif ($c->def()['stock'] && ! $c->solo_contable) {
                $sentido = $c->esNotaCredito() ? -1 : 1; // compra entra, NC de compra devuelve
                $deposito = \App\Models\Deposito::porDefecto($c->business_location_id);
                foreach ($c->items as $it) {
                    if (! $it->product_id || ! ($p = Product::find($it->product_id))) continue;
                    $costo = (float) $it->precio_unit * (1 - (float) $it->descuento / 100);
                    if ($sentido > 0 && ! $c->solo_stock && $it->actualiza_costo) {
                        $anterior = (float) $p->cost;
                        $p->precio_compra = (float) $it->precio_unit;
                        $p->descuento_proveedor = (float) $it->descuento;
                        $p->cost = $costo;
                        $p->recalcularDesdeCosto();
                        $p->save();
                        app(\App\Services\Gastronomia\RecetaVentaService::class)->insumoCambio($p, $anterior, (float) $p->cost); // Fase 53: avisa el margen de los platos que lo llevan
                    }
                    // Si se controló con la pistola, entra lo contado (lo pendiente no entra hasta que se aclare).
                    $mov = $this->stock->mover($p, $sentido * (float) ($it->cantidad_recibida ?? $it->cantidad), $deposito, $sentido > 0 ? 'in' : 'out', "Compra {$c->nombreTipo()} {$c->numeroFormateado()}", $c, $costo, $c->business_location_id, ['lote' => $it->lote, 'vencimiento' => $it->vencimiento, 'serie' => $it->serie]);
                    // Fase 42.7: el ingreso con la fecha del comprobante, si el negocio lo prefiere (si no, el día en que se carga).
                    if ($mov && $this->fechaIngresoDelComprobante($c)) $mov->forceFill(['created_at' => $c->fecha->copy()->setTimeFrom(now())])->saveQuietly();
                }
                $c->forceFill(['stock_impactado' => true])->save();
            }

            if ($c->orden_compra_id || $c->ordenes_compra) app(\App\Services\Compras\OrdenCompraService::class)->marcarRecibido($c);
            if ($c->esNotaCredito()) $rs->cerrarConNotaCredito($c->fresh(['items']));
            AuditLog::registrar('crear', $c, "Registró compra {$c->nombreTipo()} {$c->numeroFormateado()} de {$c->contact?->name}");
            app(\App\Services\Integraciones\WebhookService::class)->disparar($c->business_id, 'compra.registrada', \App\Services\Integraciones\WebhookService::comprobante($c->fresh(['items', 'contact'])));
            if (! $c->solo_stock) app(\App\Services\Contabilidad\ContabilidadService::class)->contabilizar($c->fresh(['items', 'contact']));
            // La factura del remito: el costo se escribe recién ahora (el remito no traía precios) y el remito queda facturado.
            if ($c->solo_contable && $c->remito_id) {
                foreach ($c->items as $it) { if ($it->product_id && $it->actualiza_costo && ($p = Product::find($it->product_id))) { $p->precio_compra = (float) $it->precio_unit; $p->descuento_proveedor = (float) $it->descuento; $p->cost = (float) $it->precio_unit * (1 - (float) $it->descuento / 100); $p->recalcularDesdeCosto(); $p->save(); } }
                Comprobante::whereKey($c->remito_id)->update(['notas' => \Illuminate\Support\Facades\DB::raw("COALESCE(notas, '') || ' · Facturado por {$c->nombreTipo()} {$c->numeroFormateado()}'")]);
            }
            return $c->fresh();
        });
    }

    public function anular(Comprobante $c, string $motivo = ''): Comprobante
    {
        return DB::transaction(function () use ($c, $motivo) {
            abort_if($c->estado === 'anulado', 422, 'Ya está anulada.');
            if ($c->estado === 'emitido') {
                abort_if($c->pagosImputados()->exists(), 422, 'Tiene pagos imputados. Anulá primero los pagos.');
                CuentaCorriente::where('comprobante_id', $c->id)->delete();
                CuentaCorriente::recalcularSaldo($c->contact_id);
                if ($c->stock_impactado) {
                    $sentido = $c->esNotaCredito() ? 1 : -1;
                    $deposito = \App\Models\Deposito::porDefecto($c->business_location_id);
                    foreach ($c->items as $it) {
                        if (! $it->product_id || ! ($p = Product::find($it->product_id))) continue;
                        $this->stock->mover($p, $sentido * (float) ($it->cantidad_recibida ?? $it->cantidad), $deposito, $sentido > 0 ? 'in' : 'out', "Anulación compra {$c->numeroFormateado()}", $c, null, $c->business_location_id);
                    }
                }
            }
            $c->forceFill(['estado' => 'anulado', 'anulado_en' => now(), 'saldo' => 0, 'stock_impactado' => false, 'notas' => trim(($c->notas ?? '') . "\nAnulada: {$motivo}")])->save();
            AuditLog::registrar('anular', $c, "Anuló compra {$c->numeroFormateado()}: {$motivo}");
            app(\App\Services\Contabilidad\ContabilidadService::class)->anular('compra', $c->id, $motivo);
            return $c;
        });
    }

    private function fechaIngresoDelComprobante(Comprobante $c): bool
    {
        return (app(RecepcionService::class)->config($c->business)['fecha_ingreso'] ?? 'carga') === 'comprobante';
    }

    // Sólo una empresa a la que la persona tiene acceso, y que no sea ésta.
    private function empresaStockValida($id): ?int
    {
        if (! $id) return null;
        $u = Auth::user();
        abort_unless($u && (int) $id !== (int) $u->business_id && $u->empresasAccesibles()->contains('id', (int) $id), 422, 'No tenés acceso a esa empresa.');
        return (int) $id;
    }

    // Los artículos se buscan allá por código, barras o nombre; lo que no existe se crea con el costo de la factura.
    private function entrarEnOtraEmpresa(Comprobante $c): void
    {
        $destino = \App\Models\Business::findOrFail($c->stock_en_business_id);
        $items = $c->items->filter(fn($i) => $i->product_id)->map(fn($i) => ['p' => Product::find($i->product_id), 'cant' => (float) ($i->cantidad_recibida ?? $i->cantidad), 'costo' => (float) $i->precio_unit * (1 - (float) $i->descuento / 100)])->filter(fn($x) => $x['p']);
        $origen = "Compra {$c->nombreTipo()} {$c->numeroFormateado()} de {$c->business?->name} (factura en la central)";
        self::comoEmpresa($destino, function () use ($items, $origen, $c) {
            $dep = \App\Models\Deposito::porDefecto(Auth::user()->current_location_id);
            foreach ($items as $x) {
                $src = $x['p'];
                $p = ($src->sku ? Product::where('sku', $src->sku)->first() : null) ?? ($src->barcode ? Product::where('barcode', $src->barcode)->first() : null) ?? Product::where('name', $src->name)->first()
                    ?? Product::create(['name' => $src->name, 'sku' => $src->sku, 'barcode' => $src->barcode, 'unit' => $src->unit, 'iva' => $src->iva, 'cost' => $x['costo'], 'price' => $src->price, 'controla_stock' => true, 'active' => true]);
                $p->cost = $x['costo']; $p->recalcularDesdeCosto(); $p->save();
                $mov = $this->stock->mover($p, $c->esNotaCredito() ? -$x['cant'] : $x['cant'], $dep, $c->esNotaCredito() ? 'out' : 'in', $origen, null, $x['costo']);
                if ($mov && $this->fechaIngresoDelComprobante($c)) $mov->forceFill(['created_at' => $c->fecha->copy()->setTimeFrom(now())])->saveQuietly();
            }
        });
        $c->forceFill(['stock_impactado' => true, 'notas' => trim(($c->notas ?? '') . " · La mercadería entró en {$destino->name}")])->save();
        AuditLog::registrar('editar', $c, "La mercadería de {$c->numeroFormateado()} entró en {$destino->name}");
    }

    // Trabajar un momento como otra empresa del grupo (el aislamiento por empresa sale del usuario) y volver.
    public static function comoEmpresa(\App\Models\Business $b, callable $fn)
    {
        $u = Auth::user();
        [$bid, $loc] = [$u->business_id, $u->current_location_id];
        $u->business_id = $b->id;
        $u->current_location_id = \App\Models\BusinessLocation::withoutGlobalScopes()->where('business_id', $b->id)->orderByDesc('is_default')->value('id');
        try { return $fn(); } finally { $u->business_id = $bid; $u->current_location_id = $loc; $u->syncOriginalAttributes(['business_id', 'current_location_id']); }
    }
}
