<?php

namespace App\Services\Comprobantes;

use App\Models\Acopio;
use App\Models\AcopioItem;
use App\Models\Alerta;
use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Contact;
use App\Models\CuentaCorriente;
use App\Models\Product;
use App\Models\PuntoVenta;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComprobanteService
{
    public function __construct(private AfipEmisor $afip, private \App\Services\Stock\StockService $stock) {}

    // Crea o actualiza un borrador con sus ítems y recalcula totales.
    public function guardarBorrador(array $data, ?Comprobante $c = null): Comprobante
    {
        return DB::transaction(function () use ($data, $c) {
            $user = Auth::user();
            $contact = ! empty($data['contact_id']) ? Contact::findOrFail($data['contact_id']) : null;
            $tipo = $this->resolverTipo($data['tipo'], $contact);

            $c ??= new Comprobante(['business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'user_id' => $user->id, 'direccion' => 'venta']);
            abort_if($c->exists && $c->estado !== 'borrador', 422, 'Solo se pueden editar comprobantes en borrador.');

            $dias = (int) ($data['dias_vto'] ?? $contact?->dias_pago ?? $contact?->tipoCliente?->dias_pago ?? 0);
            $origen = ($data['origen_id'] ?? $c->origen_id) ? Comprobante::find($data['origen_id'] ?? $c->origen_id) : null;
            $c->fill([
                'contact_id'      => $contact?->id,
                'vendedor_id'     => $data['vendedor_id'] ?? $c->vendedor_id ?? $contact?->vendedor_id ?? \App\Models\Vendedor::deUsuario($user->id)?->id,
                'punto_venta_id'  => $data['punto_venta_id'] ?? $this->puntoVentaPorDefecto($user)?->id,
                'origen_id'       => $data['origen_id'] ?? $c->origen_id,
                'tipo'            => $tipo,
                'fecha'           => $data['fecha'] ?? today(),
                'fecha_vto'       => ($data['condicion'] ?? 'cta_cte') === 'cta_cte' ? \Carbon\Carbon::parse($data['fecha'] ?? today())->addDays($dias) : ($data['fecha'] ?? today()),
                'condicion'       => $data['condicion'] ?? 'cta_cte',
                'es_acopio'       => (bool) ($data['es_acopio'] ?? false),
                'entrega_pendiente' => (bool) ($data['entrega_pendiente'] ?? ($c->exists ? $c->entrega_pendiente : false)),
                'fce'             => (bool) ($data['fce'] ?? ($c->exists ? $c->fce : false)),
                // Interno = no se informa a ARCA. Una nota sobre un comprobante interno también es interna.
                'sin_arca'        => (bool) ($data['sin_arca'] ?? ($c->exists ? $c->sin_arca : false)) || (bool) ($origen?->sin_arca ?? false),
                'exportacion'     => $data['exportacion'] ?? ($c->exists ? $c->exportacion : ($origen?->exportacion ?? null)),
                'fce_vto_pago'    => ($data['fce'] ?? false) ? ($data['fce_vto_pago'] ?? \Carbon\Carbon::parse($data['fecha'] ?? today())->addDays((int) ($data['dias_vto'] ?? $contact?->dias_pago ?? 30))) : null,
                'notas'           => $data['notas'] ?? null,
                'proyecto_id'     => $data['proyecto_id'] ?? $c->proyecto_id,
                // Remito: datos del transporte (para el COT de ARBA y para imprimir).
                'transportista' => $data['transportista'] ?? $c->transportista, 'transportista_cuit' => $data['transportista_cuit'] ?? $c->transportista_cuit, 'patente' => $data['patente'] ?? $c->patente,
                'bultos' => $data['bultos'] ?? $c->bultos, 'peso_kg' => $data['peso_kg'] ?? $c->peso_kg, 'domicilio_entrega' => $data['domicilio_entrega'] ?? $c->domicilio_entrega,
                'orden_trabajo_id' => $data['orden_trabajo_id'] ?? $c->orden_trabajo_id,
                'estadia_id'      => $data['estadia_id'] ?? $c->estadia_id,
                'moneda'          => $moneda = strtoupper($data['moneda'] ?? 'ARS'),
                'cotizacion'      => $cot = ($moneda === 'ARS' ? 1 : (float) (($data['cotizacion'] ?? null) ?: \App\Models\Cotizacion::valor($user->business_id))),
            ])->save();
            abort_if($moneda !== 'ARS' && $cot <= 0, 422, 'Cargá la cotización del dólar para facturar en moneda extranjera.');

            $c->items()->delete();
            $letraC = in_array($tipo, ['FC', 'NCC', 'NDC', 'FE', 'NCE', 'NDE'], true); // monotributista: no discrimina IVA; exportación: exenta de IVA
            foreach (array_values($data['items']) as $i => $it) {
                $product = ! empty($it['product_id']) ? Product::find($it['product_id']) : null;
                $al = $letraC ? 0 : (float) ($it['alicuota_iva'] ?? $product?->iva ?? 21);
                // Descuento por cantidad del artículo: se aplica solo si la línea no trae descuento propio.
                if ($product && (float) ($it['descuento'] ?? 0) == 0.0 && ($dq = $product->descuentoPorCantidad((float) $it['cantidad'])) > 0) $it['descuento'] = $dq;
                // Moneda extranjera: los precios vienen en dólares y se guardan en pesos a la cotización del comprobante.
                if ($moneda !== 'ARS') $it['precio_unit'] = round((float) $it['precio_unit'] * $cot, 2);
                $calc = ComprobanteItem::calcular((float) $it['cantidad'], (float) $it['precio_unit'], (float) ($it['descuento'] ?? 0), $al);
                $c->items()->create([
                    'product_id' => $product?->id, 'descripcion' => ($it['descripcion'] ?? null) ?: ($product?->name ?? 'Ítem'),
                    'cantidad' => $it['cantidad'], 'unidad' => $it['unidad'] ?? $product?->unit, 'precio_unit' => $it['precio_unit'], 'costo_unit' => $product?->costoPesos(),
                    'descuento' => $it['descuento'] ?? 0, 'alicuota_iva' => $al, 'orden' => $i, 'origen_item_id' => $it['origen_item_id'] ?? null, ...$calc,
                ]);
            }

            $c->impuestos()->delete();
            if ($c->esFactura() && ($pi = app(\App\Services\Fiscal\ImpuestosService::class)->percepcionIibb($user->business, $contact))) {
                $base = (float) $c->items()->sum('neto');
                $cfg = app(\App\Services\Fiscal\ImpuestosService::class)->config($user->business)['percepcion_iibb'];
                if ($base >= (float) ($cfg['minimo'] ?? 0)) $c->impuestos()->create(['tipo' => 'iibb_' . strtolower($pi['jurisdiccion']), 'base' => $base, 'alicuota' => $pi['alicuota'], 'monto' => round($base * $pi['alicuota'] / 100, 2)]);
            }
            // Percepciones de IVA y de Ganancias (agente de percepción): sobre el neto gravado.
            if ($c->esFactura()) {
                $imp = app(\App\Services\Fiscal\ImpuestosService::class);
                $baseGravada = (float) $c->items()->where('alicuota_iva', '>', 0)->sum('neto');
                if (($pv = $imp->percepcionIva($user->business, $contact)) && $baseGravada >= $pv['minimo'] && $baseGravada > 0) $c->impuestos()->create(['tipo' => 'perc_iva', 'base' => $baseGravada, 'alicuota' => $pv['alicuota'], 'monto' => round($baseGravada * $pv['alicuota'] / 100, 2)]);
                if (($pg = $imp->percepcionGanancias($user->business, $contact)) && $baseGravada >= $pg['minimo'] && $baseGravada > 0) $c->impuestos()->create(['tipo' => 'perc_ganancias', 'base' => $baseGravada, 'alicuota' => $pg['alicuota'], 'monto' => round($baseGravada * $pg['alicuota'] / 100, 2)]);
            }
            // Novedades de facturación: precio distinto al de la lista del cliente queda auditado (quién, cuánto, en qué comprobante).
            if ($contact && $c->esFactura()) {
                $lista = (int) ($contact->lista_precios ?: 1);
                foreach ($c->items as $it) {
                    if (! $it->product_id || ! ($p = Product::find($it->product_id))) continue;
                    $ref = $p->precioLista($lista); $dif = $ref > 0 ? ((float) $it->precio_unit - $ref) / $ref * 100 : 0;
                    if (abs($dif) >= 0.5) AuditLog::registrar('precio_modificado', $c, "{$p->name}: lista {$lista} $ " . number_format($ref, 2, ',', '.') . " → $ " . number_format((float) $it->precio_unit, 2, ',', '.') . ' (' . ($dif > 0 ? '+' : '') . round($dif, 1) . '%) en ' . $c->nombreTipo() . ($c->numeroFormateado() ? ' ' . $c->numeroFormateado() : ' borrador'), ['precio' => $ref], ['precio' => (float) $it->precio_unit, 'articulo' => $p->name, 'lista' => $ref, 'facturado' => (float) $it->precio_unit, 'cantidad' => (float) $it->cantidad, 'diferencia' => round(((float) $it->precio_unit - $ref) * (float) $it->cantidad, 2)]);
                }
            }

            $c->recalcularTotales();
            if ($moneda !== 'ARS') $c->forceFill(['total_me' => round((float) $c->total / $cot, 2), 'neto_me' => round((float) $c->neto / $cot, 2)])->save();
            return $c->fresh(['items', 'contact']);
        });
    }

    // Emite: numera, pide CAE, impacta cuenta corriente, stock y acopio.
    public function emitir(Comprobante $c): Comprobante
    {
        return DB::transaction(function () use ($c) {
            abort_if($c->estado !== 'borrador', 422, 'El comprobante ya fue emitido.');
            abort_if($c->items()->count() === 0, 422, 'El comprobante no tiene ítems.');
            if ($c->def()['cc'] !== 0 && ! $c->contact_id) {
                throw ValidationException::withMessages(['contact_id' => 'Elegí un cliente para emitir este comprobante.']);
            }
            if ($c->esFactura() && $c->contact && $c->condicion === 'cta_cte' && (float) $c->contact->credit_limit > 0
                && (float) $c->contact->balance + (float) $c->total > (float) $c->contact->credit_limit) {
                throw ValidationException::withMessages(['contact_id' => 'El cliente supera su límite de crédito. Cobrá al contado o ampliá el límite.']);
            }

            $business = $c->emisor();
            $pv = $c->puntoVenta ?? $this->puntoVentaPorDefecto(Auth::user());
            // Sucursal con CUIT propio: numera con sus propios puntos de venta (los de ARCA de ese CUIT), nunca con los de la casa central.
            if ($c->location?->tieneCuitPropio() && $c->esFiscal() && (! $pv || $pv->business_location_id !== $c->business_location_id)) {
                $pv = PuntoVenta::where('activo', true)->where('business_location_id', $c->business_location_id)->orderBy('numero')->first();
                abort_if(! $pv, 422, "La sucursal {$c->location->name} factura con su propio CUIT ({$c->location->cuit}) y no tiene un punto de venta propio. Crealo en Configuración → Puntos de venta.");
            }
            abort_if(! $pv, 422, 'Configurá un punto de venta antes de emitir.');
            $c->punto_venta = $pv->numero;
            $c->punto_venta_id = $pv->id;

            // Interno: no pasa por ARCA y numera aparte, para no pisar la numeración fiscal.
            $res = $c->sin_arca && $c->esFiscal() ? ['estado' => 'interno', 'numero' => $pv->proximoNumero($c->tipo . '-X')] : $this->afip->emitir($c, $business);
            if ($res['estado'] === 'rechazado') {
                $ex = $res['explicacion'] ?? null;
                throw ValidationException::withMessages(['afip' => 'ARCA rechazó el comprobante: ' . ($ex ? "{$ex['que']} {$ex['como']} (detalle: {$res['error']})" : $res['error'])]);
            }
            // Contingencia: ARCA no respondió. El comprobante sale sin número fiscal ni CAE y se reintenta solo (afip:reintentar).
            $pendiente = $res['estado'] === 'pendiente';
            $numero = $pendiente ? null : ($res['numero'] ?? $pv->proximoNumero($c->tipo));
            if ($res['estado'] === 'aprobado') {
                $pv->sincronizarUltimo($c->tipo, $numero);
            }

            $c->forceFill([
                'numero' => $numero, 'estado' => 'emitido', 'emitido_en' => now(),
                'afip_estado' => $res['estado'], 'cae' => $res['cae'] ?? null, 'cae_vto' => $res['cae_vto'] ?? null,
                'afip_respuesta' => $res['respuesta'] ?? ($pendiente ? ['error' => $res['error'] ?? null, 'explicacion' => $res['explicacion'] ?? null, 'intentos' => 1] : null),
                'saldo' => $c->def()['cc'] > 0 ? $c->total : 0,
            ])->save();

            $this->impactarCuentaCorriente($c);
            $this->impactarStock($c);
            $this->registrarEntregasYFacturacion($c);
            if ($c->es_acopio && $c->esFactura()) {
                $this->crearAcopio($c);
            }
            if ($c->origen && $c->origen->tipo === 'PRE' && $c->esFactura()) {
                Alerta::where('modelo', 'Comprobante')->where('modelo_id', $c->origen_id)->update(['resuelta_en' => now()]);
            }

            AuditLog::registrar('emitir', $c, "Emitió {$c->nombreTipo()} {$c->numeroFormateado()}");
            app(\App\Services\Contabilidad\ContabilidadService::class)->contabilizar($c->fresh(['items', 'contact']));
            app(\App\Services\Integraciones\WebhookService::class)->disparar($c->business_id, 'comprobante.emitido', \App\Services\Integraciones\WebhookService::comprobante($c->fresh(['items', 'contact'])));
            try { app(\App\Services\Ventas\FidelizacionService::class)->acreditarPorComprobante($c->fresh(['contact', 'business'])); } catch (\Throwable $e) { \Log::warning('Puntos: ' . $e->getMessage()); }
            return $c->fresh();
        });
    }

    // Vuelve a pedir el CAE de un comprobante pendiente (contingencia). Si ARCA lo autoriza, recién ahí recibe su número fiscal.
    public function reintentarCae(Comprobante $c): array
    {
        abort_unless($c->estado === 'emitido' && $c->afip_estado === 'pendiente', 422, 'Este comprobante no está pendiente de CAE.');
        $pv = $c->puntoVenta;
        $res = $this->afip->emitir($c->fresh(['items', 'contact', 'impuestos', 'origen']), $c->business);
        $previo = $c->afip_respuesta ?? [];
        if ($res['estado'] === 'aprobado') {
            $pv?->sincronizarUltimo($c->tipo, $res['numero']);
            $c->forceFill(['numero' => $res['numero'], 'afip_estado' => 'aprobado', 'cae' => $res['cae'], 'cae_vto' => $res['cae_vto'], 'afip_respuesta' => $res['respuesta'] ?? null])->save();
            CuentaCorriente::where('comprobante_id', $c->id)->update(['concepto' => $c->nombreTipo() . ' ' . $c->numeroFormateado()]);
            Alerta::where('modelo', 'Comprobante')->where('modelo_id', $c->id)->where('tipo', 'cae_pendiente')->update(['resuelta_en' => now()]);
            AuditLog::registrar('emitir', $c, "ARCA autorizó {$c->nombreTipo()} {$c->numeroFormateado()} (estaba pendiente)");
            return ['estado' => 'aprobado', 'cae' => $res['cae'], 'numero' => $res['numero']];
        }
        if ($res['estado'] === 'rechazado') {
            $c->forceFill(['afip_respuesta' => ['error' => $res['error'], 'explicacion' => $res['explicacion'] ?? null, 'intentos' => (int) ($previo['intentos'] ?? 0) + 1, 'rechazado' => true]])->save();
            return ['estado' => 'rechazado', 'error' => $res['error'], 'explicacion' => $res['explicacion'] ?? null];
        }
        $c->forceFill(['afip_respuesta' => ['error' => $res['error'] ?? null, 'explicacion' => $res['explicacion'] ?? null, 'intentos' => (int) ($previo['intentos'] ?? 0) + 1]])->save();
        return ['estado' => 'pendiente', 'error' => $res['error'] ?? null];
    }

    public function verificarEnArca(Comprobante $c): array
    {
        return $this->afip->verificar($c, $c->business);
    }

    public function anular(Comprobante $c, string $motivo = ''): Comprobante
    {
        return DB::transaction(function () use ($c, $motivo) {
            abort_if($c->estado === 'anulado', 422, 'Ya está anulado.');
            if ($c->estado === 'emitido' && $c->esFiscal() && $c->afip_estado === 'aprobado') {
                abort(422, 'Un comprobante fiscal aprobado por AFIP no se anula: emití una nota de crédito.');
            }
            if ($c->estado === 'emitido' && $c->imputaciones()->exists()) {
                abort(422, 'Tiene cobros imputados. Anulá primero los cobros.');
            }
            if ($c->estado === 'emitido') {
                CuentaCorriente::where('comprobante_id', $c->id)->delete();
                if ($c->contact_id) {
                    CuentaCorriente::recalcularSaldo($c->contact_id);
                }
                if ($c->stock_impactado) {
                    $this->revertirStock($c);
                }
                $c->acopio?->delete();
            }
            $c->forceFill(['estado' => 'anulado', 'anulado_en' => now(), 'saldo' => 0, 'notas' => trim(($c->notas ?? '') . "\nAnulado: {$motivo}")])->save();
            AuditLog::registrar('anular', $c, "Anuló {$c->nombreTipo()} {$c->numeroFormateado()}: {$motivo}");
            app(\App\Services\Integraciones\WebhookService::class)->disparar($c->business_id, 'comprobante.anulado', ['id' => $c->id, 'tipo' => $c->tipo, 'numero' => $c->numeroFormateado(), 'motivo' => $motivo]);
            app(\App\Services\Contabilidad\ContabilidadService::class)->anular('venta', $c->id, $motivo);
            return $c;
        });
    }

    // Presupuesto o remito -> factura; factura -> remito o nota de crédito.
    public function convertir(Comprobante $origen, string $tipoDestino, array $extra = []): Comprobante
    {
        abort_if($origen->estado !== 'emitido', 422, 'Solo se convierten comprobantes emitidos.');
        $contact = $origen->contact;
        $data = [
            'contact_id' => $origen->contact_id, 'tipo' => $tipoDestino, 'origen_id' => $origen->id,
            'fecha' => today()->toDateString(), 'condicion' => $extra['condicion'] ?? $origen->condicion, 'es_acopio' => $extra['es_acopio'] ?? false,
            'notas' => "Generado desde {$origen->nombreTipo()} {$origen->numeroFormateado()}",
            'items' => $origen->items->map(fn($i) => ['product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => $i->cantidad, 'unidad' => $i->unidad, 'precio_unit' => $i->precio_unit, 'descuento' => $i->descuento, 'alicuota_iva' => $i->alicuota_iva])->all(),
        ];
        return $this->guardarBorrador($data);
    }

    // FA/FB/FC según empresa y cliente; el resto se respeta.
    public function resolverTipo(string $tipo, ?Contact $contact): string
    {
        $business = Auth::user()->business;
        $letra = Contact::letraPara($business, $contact);
        return match ($tipo) {
            'FA', 'FB', 'FC', 'FX' => 'F' . $letra,
            'NCA', 'NCB', 'NCC', 'NCX' => 'NC' . $letra,
            'NDA', 'NDB', 'NDC', 'NDX' => 'ND' . $letra,
            default => $tipo,
        };
    }

    public function puntoVentaPorDefecto($user): ?PuntoVenta
    {
        return PuntoVenta::where('activo', true)
            ->orderByRaw('CASE WHEN business_location_id = ? THEN 0 ELSE 1 END', [$user->current_location_id])
            ->orderBy('numero')->first();
    }

    private function impactarCuentaCorriente(Comprobante $c): void
    {
        $signo = $c->def()['cc'];
        if ($signo === 0 || ! $c->contact_id) {
            return;
        }
        CuentaCorriente::create([
            'business_id' => $c->business_id, 'contact_id' => $c->contact_id, 'comprobante_id' => $c->id,
            'fecha' => $c->fecha, 'fecha_vto' => $signo > 0 ? $c->fecha_vto : null, 'tipo' => $c->def()['grupo'],
            'concepto' => "{$c->nombreTipo()} {$c->numeroFormateado()}",
            'debe' => $signo > 0 ? $c->total : 0, 'haber' => $signo < 0 ? $c->total : 0,
        ]);
        if ($signo < 0 && $c->origen_id) {
            // La NC cancela saldo de la factura de origen.
            $origen = $c->origen;
            $aplicar = min((float) $origen->saldo, (float) $c->total);
            $origen->decrement('saldo', $aplicar);
        }
        CuentaCorriente::recalcularSaldo($c->contact_id);
    }

    // Lleva la cuenta de qué se entregó y qué se facturó, línea por línea (entregas parciales).
    private function registrarEntregasYFacturacion(Comprobante $c): void
    {
        if ($c->esFactura() && ! $c->entrega_pendiente && ! $c->es_acopio) {
            $c->items()->update(['cantidad_entregada' => DB::raw('cantidad')]);
        }
        if ($c->esFactura() && $c->origen && $c->origen->tipo === 'REM') {
            $c->items()->update(['cantidad_entregada' => DB::raw('cantidad')]);
        }
        if ($c->tipo === 'REM' && $c->origen && $c->origen->esFactura()) {
            $c->items()->update(['cantidad_facturada' => DB::raw('cantidad')]);
        }
        foreach ($c->items as $it) {
            if (! $it->origen_item_id) continue;
            $oi = ComprobanteItem::find($it->origen_item_id);
            if (! $oi) continue;
            if ($c->esFactura()) $oi->update(['cantidad_facturada' => round((float) $oi->cantidad_facturada + (float) $it->cantidad, 3)]);
            if ($c->tipo === 'REM') $oi->update(['cantidad_entregada' => round((float) $oi->cantidad_entregada + (float) $it->cantidad, 3)]);
        }
        // Conversión completa sin ítems vinculados (flujo simple): se marca todo.
        if ($c->origen && $c->items->every(fn($i) => ! $i->origen_item_id)) {
            if ($c->esFactura() && $c->origen->tipo === 'REM') $c->origen->items()->update(['cantidad_facturada' => DB::raw('cantidad')]);
            if ($c->tipo === 'REM' && $c->origen->esFactura()) $c->origen->items()->update(['cantidad_entregada' => DB::raw('cantidad')]);
        }
    }

    private function impactarStock(Comprobante $c): void
    {
        if (! $c->def()['stock'] || $c->es_acopio || ($c->esFactura() && $c->entrega_pendiente)) {
            return;
        }
        // Si el origen ya movió stock (remito -> factura o factura -> remito) no se repite.
        if ($c->origen && $c->origen->stock_impactado && in_array($c->def()['grupo'], ['factura', 'remito'], true)) {
            return;
        }
        if ($c->esNotaCredito() && $c->origen && $c->origen->esFactura() && ! $c->origen->stock_impactado) {
            return; // la factura original no movió stock (entrega pendiente / desde remito)
        }
        $sentido = $c->esNotaCredito() ? 1 : -1; // NC devuelve mercadería
        $deposito = \App\Models\Deposito::porDefecto($c->business_location_id);
        foreach ($c->items as $it) {
            if (! $it->product_id || ! ($p = Product::find($it->product_id))) {
                continue;
            }
            $this->stock->mover($p, $sentido * (float) $it->cantidad, $deposito, $sentido > 0 ? 'in' : 'out', "{$c->nombreTipo()} {$c->numeroFormateado()}", $c, null, $c->business_location_id);
        }
        $c->forceFill(['stock_impactado' => true])->save();
    }

    private function revertirStock(Comprobante $c): void
    {
        $sentido = $c->esNotaCredito() ? -1 : 1;
        $deposito = \App\Models\Deposito::porDefecto($c->business_location_id);
        foreach ($c->items as $it) {
            if (! $it->product_id || ! ($p = Product::find($it->product_id))) {
                continue;
            }
            $this->stock->mover($p, $sentido * (float) $it->cantidad, $deposito, $sentido > 0 ? 'in' : 'out', "Anulación {$c->nombreTipo()} {$c->numeroFormateado()}", $c, null, $c->business_location_id);
        }
        $c->forceFill(['stock_impactado' => false])->save();
    }

    private function crearAcopio(Comprobante $c): void
    {
        $acopio = Acopio::create([
            'business_id' => $c->business_id, 'business_location_id' => $c->business_location_id, 'contact_id' => $c->contact_id,
            'comprobante_id' => $c->id, 'fecha' => $c->fecha, 'fecha_limite' => $c->fecha->copy()->addMonths(6), 'estado' => 'abierto',
        ]);
        foreach ($c->items as $it) {
            AcopioItem::create(['acopio_id' => $acopio->id, 'product_id' => $it->product_id, 'descripcion' => $it->descripcion, 'cantidad_facturada' => $it->cantidad, 'precio_congelado' => $it->precio_unit]);
        }
    }
}
