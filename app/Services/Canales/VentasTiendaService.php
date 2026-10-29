<?php

namespace App\Services\Canales;

use App\Models\AuditLog;
use App\Models\Canal;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\PedidoWeb;
use App\Models\Product;
use App\Models\Publicacion;
use App\Services\Comprobantes\ComprobanteService;
use App\Services\Envios\EnvioService;
use Illuminate\Validation\ValidationException;

// Fase 30.4 y 30.5: cada venta de tienda llega lista para facturar. Quién factura (la tienda o el ERP), la cuenta fija con los datos del
// comprador, la fecha de corte, el modo automático, las cancelaciones que devuelven el stock, la factura subida a ML y mandada por el
// mensaje de la venta, el aviso de envío con seguimiento, y lo que MercadoLibre se queda de cada venta.
class VentasTiendaService
{
    public const CONDICIONES_ML = ['IVA Responsable Inscripto' => 'Responsable Inscripto', 'Responsable Inscripto' => 'Responsable Inscripto', 'Monotributo' => 'Monotributista', 'Responsable Monotributo' => 'Monotributista', 'Monotributista' => 'Monotributista', 'IVA Exento' => 'Exento', 'Exento' => 'Exento', 'Consumidor Final' => 'Consumidor Final', 'IVA Sujeto Exento' => 'Exento'];

    public function __construct(private TiendaService $tienda, private ConexionesService $cx, private ComprobanteService $comprobantes, private EnvioService $envios) {}

    public function canalDe(PedidoWeb $p): ?Canal
    {
        return Canal::withoutGlobalScopes()->where('business_id', $p->business_id)->where('tipo', $p->canal)->orderByDesc('activo')->first();
    }

    // Lo que hay que mirar antes de confirmar: condición desconocida, descuento, artículo no publicado desde acá, envío a convenir.
    public function avisos(PedidoWeb $p, ?Canal $c): array
    {
        $a = [];
        $cond = $p->cliente['condicion_iva'] ?? null;
        if ($cond && ! isset(self::CONDICIONES_ML[$cond]) && ! in_array($cond, Contact::CONDICIONES_IVA, true)) $a[] = "El comprador dice «{$cond}», una condición de IVA que el sistema no conoce: sale con la de la cuenta.";
        if (! empty($p->cliente['cuit']) && ! $cond) $a[] = "Cargó el CUIT/DNI {$p->cliente['cuit']} pero no dice su condición de IVA: sale con la de la cuenta. Si querés facturarle A, cargalo en Clientes antes de confirmar.";
        if ((float) $p->descuento > 0.005) $a[] = 'La venta tuvo un descuento de la tienda (' . number_format((float) $p->descuento, 2, ',', '.') . '): no va en la factura.';
        if ((float) $p->envio > 0.005 && $p->canal !== 'tienda') $a[] = 'El costo de envío que cobró la tienda no va en la factura.';
        if ($p->entrega === 'envio' && empty($p->cliente['direccion']) && empty($p->envio_datos['shipment_id'])) $a[] = 'El envío es a convenir con el comprador.';
        if ($c) foreach ($p->items as $it) {
            if (! empty($it['product_id']) && ! empty($it['external_item']) && ! Publicacion::withoutGlobalScopes()->where('canal_id', $c->id)->where('external_id', (string) $it['external_item'])->where('origen', 'publicada')->exists()) { $a[] = "«{$it['descripcion']}» no es de los publicados desde acá (está atado a mano)."; break; }
        }
        return $a;
    }

    // ¿Está todo en orden para confirmar? Si no, los motivos, con nombre y apellido.
    public function listo(PedidoWeb $p, ?Canal $c): array
    {
        $m = [];
        if ($p->estado !== 'nuevo') $m[] = 'El pedido ya fue procesado.';
        if ($c && $c->tipo === 'mercadolibre' && ! $c->cfg('factura_por')) $m[] = 'Falta decir quién factura las ventas de MercadoLibre (Configuración → Tienda y canales → Ajustes). Es un clic, una sola vez.';
        if ($c && ($fc = $c->cfg('fecha_corte')) && $p->created_at->lt(\Carbon\Carbon::parse($fc)->startOfDay())) $m[] = "Es anterior a la fecha desde la que entran las ventas ({$fc}): ya lo resolviste a mano. Se ve, pero no se confirma ni descuenta stock.";
        foreach ($p->items as $it) if (empty($it['product_id'])) $m[] = "«{$it['descripcion']}» todavía no tiene artículo: emparejalo en Stock → Tienda online → Las que ya tenés en la tienda.";
        return ['ok' => ! $m, 'motivos' => $m];
    }

    // Confirmar una venta de tienda: a la cuenta fija con los datos del comprador; remito si factura la tienda, factura si factura el ERP.
    public function confirmar(PedidoWeb $p, array $opt = []): PedidoWeb
    {
        $c = $this->canalDe($p);
        if (! $c || ! in_array($p->canal, ['mercadolibre', 'woocommerce', 'tiendanube', 'shopify'], true)) return $this->tienda->confirmar($p, $opt);
        $l = $this->listo($p, $c);
        if (! $l['ok']) throw ValidationException::withMessages(['pedido' => implode(' ', $l['motivos'])]);
        $facturaTienda = $c->tipo === 'mercadolibre' && $c->cfg('factura_por') === 'tienda';
        $tipo = $facturaTienda ? 'REM' : ($opt['tipo'] ?? 'FX');
        $cli = $p->cliente;
        $cond = self::CONDICIONES_ML[$cli['condicion_iva'] ?? ''] ?? (in_array($cli['condicion_iva'] ?? '', Contact::CONDICIONES_IVA, true) ? $cli['condicion_iva'] : null);
        $receptor = ! empty($cli['cuit']) || $cond ? ['nombre' => $cli['nombre'] ?? 'Comprador', 'documento' => $cli['cuit'] ?? null, 'condicion_iva' => $cond ?? 'Consumidor Final', 'address' => $cli['direccion'] ?? null, 'email' => $cli['email'] ?? null, 'phone' => $cli['telefono'] ?? null] : null;
        $notas = ($facturaTienda ? 'Factura MercadoLibre' . (! empty($p->cargos['factura_ml']) ? " {$p->cargos['factura_ml']}" : ' (la emite ML)') . ' · ' : '') . ($p->external_id ? 'Venta ' . ($p->cargos['pack_id'] ?? $p->external_id) . ' · ' : '');
        $p = $this->tienda->confirmar($p, ['tipo' => $tipo, 'emitir' => $opt['emitir'] ?? true, 'contact_id' => $c->cfg('contact_id'), 'receptor_datos' => $receptor, 'notas' => $notas]);
        $p->forceFill(['avisos' => $this->avisos($p, $c)])->save();
        return $p;
    }

    // Modo automático: la que llega con todo en orden entra sola; la que no, queda por confirmar con lo que le falta anotado.
    public function entradaAutomatica(PedidoWeb $p, Canal $c): void
    {
        $l = $this->listo($p, $c);
        $avisos = $this->avisos($p, $c);
        if (! $c->cfg('automatico')) { $p->forceFill(['avisos' => array_merge($avisos, $l['motivos'])])->save(); return; }
        if (! $l['ok']) { $p->forceFill(['avisos' => array_merge(['No entró sola: ' . implode(' ', $l['motivos'])], $avisos)])->save(); return; }
        try { \Illuminate\Support\Facades\Auth::user() ?: \Illuminate\Support\Facades\Auth::setUser($c->business->users()->orderBy('id')->first()); $this->confirmar($p); $p->forceFill(['notas' => trim(($p->notas ?? '') . ' · Entró sola')])->save(); }
        catch (\Throwable $e) { $p->forceFill(['avisos' => array_merge(['No entró sola: ' . mb_substr($e->getMessage(), 0, 200)], $avisos)])->save(); }
    }

    // La tienda canceló (o el correo devolvió): vuelve el stock. Remito → ingreso de stock; factura → nota de crédito.
    public function devolverStock(PedidoWeb $p): PedidoWeb
    {
        $c = $p->comprobante;
        if (! $c || $c->estado !== 'emitido') { $p->forceFill(['estado' => 'cancelado', 'devolucion' => 'resuelta'])->save(); return $p; }
        if ($c->tipo === 'REM') {
            $stock = app(\App\Services\Stock\StockService::class);
            foreach ($c->items as $it) if ($it->product_id && ($pr = Product::withoutGlobalScopes()->find($it->product_id))) $stock->entrada($pr, (float) $it->cantidad, 'Devolución de ' . (PedidoWeb::CANALES[$p->canal] ?? $p->canal) . " {$p->numeroFormateado()}", $p, null, (float) $pr->cost);
        } elseif ($c->esFactura()) {
            $nc = $this->comprobantes->convertir($c, 'NCX');
            $this->comprobantes->emitir($nc);
        }
        $p->forceFill(['estado' => 'cancelado', 'devolucion' => 'resuelta'])->save();
        AuditLog::registrar('editar', $p, "Devolución de {$p->numeroFormateado()}: volvió el stock");
        return $p;
    }

    // Subir la factura a la venta de MercadoLibre (sólo PDF, hasta 1 MB) y mandarla por el mensaje de la venta.
    public function subirFacturaMl(PedidoWeb $p): void
    {
        $c = $this->canalDe($p); $comp = $p->comprobante;
        if (! $comp || ! $comp->esFactura() || $comp->estado !== 'emitido') throw ValidationException::withMessages(['factura' => 'Todavía no hay factura emitida para esta venta.']);
        [$pdf, $nombre] = $this->envios->pdf($comp);
        if (strlen($pdf) > 1024 * 1024) throw ValidationException::withMessages(['factura' => 'MercadoLibre acepta hasta 1 MB y la factura pesa ' . round(strlen($pdf) / 1024) . ' KB.']);
        $pack = $p->cargos['pack_id'] ?? $p->external_id;
        $r = $this->cx->ml($c)->attach('fiscal_document', $pdf, $nombre)->post("/packs/{$pack}/fiscal_documents");
        if (! $r->successful()) throw ValidationException::withMessages(['factura' => 'MercadoLibre rechazó la factura: ' . mb_substr($r->json('message') ?? $r->body(), 0, 200)]);
        $p->forceFill(['factura_subida_at' => now()])->save();
    }

    public function mandarFacturaMl(PedidoWeb $p): void
    {
        $c = $this->canalDe($p); $comp = $p->comprobante;
        if (! $comp || $comp->estado !== 'emitido') throw ValidationException::withMessages(['factura' => 'Todavía no hay comprobante emitido para esta venta.']);
        [$pdf, $nombre] = $this->envios->pdf($comp);
        $uid = $c->external_user_id ?: ($c->credenciales['user_id'] ?? '');
        // Un cliente nuevo por llamada: el adjunto va multipart y el mensaje va JSON.
        $a = $this->cx->ml($c)->attach('file', $pdf, $nombre)->post('/messages/attachments?tag=post_sale&site_id=MLA');
        if (! $a->successful()) throw ValidationException::withMessages(['factura' => 'No se pudo adjuntar la factura: ' . mb_substr($a->body(), 0, 200)]);
        $pack = $p->cargos['pack_id'] ?? $p->external_id; $comprador = $p->cargos['comprador_id'] ?? null;
        $r = $this->cx->ml($c)->post("/messages/packs/{$pack}/sellers/{$uid}?tag=post_sale", ['from' => ['user_id' => $uid], 'to' => ['user_id' => $comprador], 'text' => "¡Gracias por tu compra! Te adjuntamos la factura {$comp->numeroFormateado()}.", 'attachments' => [$a->json('id')]]);
        if (! $r->successful()) throw ValidationException::withMessages(['factura' => 'MercadoLibre no aceptó el mensaje: ' . mb_substr($r->json('message') ?? $r->body(), 0, 200)]);
        $p->forceFill(['factura_enviada_at' => now()])->save();
    }

    // Avisar el envío en la tienda, con el seguimiento: la tienda le manda el mail al comprador.
    public function marcarEnviado(PedidoWeb $p, ?string $tracking): void
    {
        $c = $this->canalDe($p); if (! $c || ! $p->external_id) return;
        try {
            match ($c->tipo) {
                'woocommerce' => $this->cx->woo($c)->put("orders/{$p->external_id}", ['status' => 'completed', 'meta_data' => $tracking ? [['key' => '_tracking_number', 'value' => $tracking]] : []]),
                'tiendanube' => $this->cx->tn($c)->post("orders/{$p->external_id}/fulfill", array_filter(['shipping_tracking_number' => $tracking, 'notify_customer' => true])),
                'shopify' => $this->enviadoShopify($c, $p, $tracking),
                default => null,
            };
        } catch (\Throwable $e) { $p->forceFill(['avisos' => array_merge($p->avisos ?? [], ['No se pudo avisar el envío en la tienda: ' . mb_substr($e->getMessage(), 0, 120)])])->save(); }
        $p->forceFill(['tracking' => $tracking ?: $p->tracking])->save();
    }

    private function enviadoShopify(Canal $c, PedidoWeb $p, ?string $tracking): void
    {
        $s = $this->cx->shopify($c);
        $fos = $s->get("orders/{$p->external_id}/fulfillment_orders.json")->json('fulfillment_orders') ?? [];
        $lineas = collect($fos)->filter(fn($fo) => in_array($fo['status'] ?? '', ['open', 'in_progress'], true))->map(fn($fo) => ['fulfillment_order_id' => $fo['id']])->values()->all();
        if ($lineas) $s->post('fulfillments.json', ['fulfillment' => ['line_items_by_fulfillment_order' => $lineas, 'notify_customer' => true] + ($tracking ? ['tracking_info' => ['number' => $tracking]] : [])]);
    }

    // ---- Cargos de MercadoLibre: lo que se queda de cada venta, y el mes para el contador ----
    public function cargosDe(PedidoWeb $p): ?array
    {
        $pagos = $p->cargos['pagos'] ?? null; if (! $pagos) return null;
        $cobrado = round(collect($pagos)->sum('monto'), 2); $cargo = round(collect($pagos)->sum('cargo'), 2); $envio = round(collect($pagos)->sum('envio'), 2); $ret = round(collect($pagos)->sum('retenciones'), 2); $cuotas = round(collect($pagos)->sum('cuotas'), 2);
        $recibe = round($cobrado - $cargo - $envio - $ret - $cuotas, 2);
        $ri = ($p->business->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        $costo = round(collect($p->items)->sum(fn($i) => ! empty($i['product_id']) ? (float) (Product::withoutGlobalScopes()->find($i['product_id'])?->costoPesos() ?? Product::withoutGlobalScopes()->find($i['product_id'])?->cost ?? 0) * (float) $i['cantidad'] : 0), 2);
        $neto = $ri ? round((float) $p->subtotal / 1.21, 2) : (float) $p->subtotal;
        return ['precio' => (float) $p->total, 'cobrado' => $cobrado, 'cargo_venta' => $cargo, 'cuotas' => $cuotas, 'envio' => $envio, 'retenciones' => $ret, 'recibe' => $recibe, 'libera' => collect($pagos)->pluck('liberacion')->filter()->map(fn($d) => \Carbon\Carbon::parse($d)->format('d/m'))->first(), 'costo' => $costo, 'deja' => round($neto - ($ri ? ($cargo + $cuotas + $envio) / 1.21 : $cargo + $cuotas + $envio) - $costo, 2), 'neto' => $neto];
    }

    public function resumenMes(Canal $c, string $mes): array
    {
        $desde = \Carbon\Carbon::parse($mes . '-01'); $hasta = $desde->copy()->endOfMonth();
        $pedidos = PedidoWeb::where('canal', $c->tipo)->whereBetween('created_at', [$desde, $hasta])->where('estado', '!=', 'cancelado')->whereNotNull('cargos')->get();
        $filas = $pedidos->map(fn($p) => ['id' => $p->id, 'numero' => $p->numeroFormateado(), 'fecha' => $p->created_at->format('d/m'), 'venta' => $p->cargos['pack_id'] ?? $p->external_id, 'comprador' => $p->cliente['nombre'] ?? '', 'comprobante' => $p->comprobante ? $p->comprobante->nombreTipo() . ' ' . $p->comprobante->numeroFormateado() : null] + ($this->cargosDe($p) ?? []))->filter(fn($f) => isset($f['cobrado']))->values();
        $sum = fn($k) => round($filas->sum($k), 2);
        return ['mes' => $mes, 'filas' => $filas->all(), 'ventas' => $filas->count(), 'vendido' => $sum('precio'), 'cargo_venta' => $sum('cargo_venta'), 'cuotas' => $sum('cuotas'), 'envio' => $sum('envio'), 'retenciones' => $sum('retenciones'), 'recibe' => $sum('recibe'), 'deja' => $sum('deja'), 'compra_id' => Comprobante::compras()->where('notas', 'like', "%Cargos MercadoLibre {$mes}%")->value('id')];
    }

    public function csvContador(Canal $c, string $mes): string
    {
        $r = $this->resumenMes($c, $mes);
        $out = "Fecha;Venta;Comprador;Comprobante;Precio;Cobrado;Cargo por vender;Cuotas;Envio;Retenciones;Recibe;Libera;Costo;Deja\n";
        foreach ($r['filas'] as $f) $out .= implode(';', [$f['fecha'], $f['venta'], str_replace(';', ',', $f['comprador']), $f['comprobante'], $f['precio'], $f['cobrado'], $f['cargo_venta'], $f['cuotas'], $f['envio'], $f['retenciones'], $f['recibe'], $f['libera'], $f['costo'], $f['deja']]) . "\n";
        return "\xEF\xBB\xBF" . $out;
    }

    // Los cargos del mes son una compra al proveedor MERCADO LIBRE S.R.L. (ML factura una vez por mes); queda en borrador para completar con el número real.
    public function compraCargos(Canal $c, string $mes): Comprobante
    {
        $r = $this->resumenMes($c, $mes);
        if ($r['compra_id']) return Comprobante::findOrFail($r['compra_id']);
        if ($r['cargo_venta'] + $r['cuotas'] + $r['envio'] <= 0.005) throw ValidationException::withMessages(['mes' => 'No hay cargos en ese mes.']);
        $prov = Contact::suppliers()->where('cuit', '30-70308853-4')->first() ?? Contact::create(['business_id' => $c->business_id, 'type' => 'supplier', 'name' => 'MERCADO LIBRE S.R.L.', 'cuit' => '30-70308853-4', 'condicion_iva' => 'Responsable Inscripto', 'is_active' => true, 'lista_precios' => 1, 'dias_pago' => 0]);
        $ri = ($c->business->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        $neto = fn($v) => $ri ? round($v / 1.21, 2) : round($v, 2);
        $items = array_values(array_filter([
            $r['cargo_venta'] > 0 ? ['descripcion' => "Cargo por vender · MercadoLibre {$mes}", 'cantidad' => 1, 'precio_unit' => $neto($r['cargo_venta']), 'alicuota_iva' => 21] : null,
            $r['cuotas'] > 0 ? ['descripcion' => "Costo por ofrecer cuotas · {$mes}", 'cantidad' => 1, 'precio_unit' => $neto($r['cuotas']), 'alicuota_iva' => 21] : null,
            $r['envio'] > 0 ? ['descripcion' => "Envíos a cargo del vendedor · {$mes}", 'cantidad' => 1, 'precio_unit' => $neto($r['envio']), 'alicuota_iva' => 21] : null,
        ]));
        $compra = app(\App\Services\Compras\CompraService::class)->guardarBorrador(['contact_id' => $prov->id, 'tipo' => $ri ? 'FA' : 'FB', 'fecha' => today()->toDateString(), 'condicion' => 'contado', 'items' => $items, 'notas' => "Cargos MercadoLibre {$mes} · armado desde Tienda online. Completá el número y el CAE de la factura de ML y controlá contra el detalle; las retenciones (SIRTAC, débitos y créditos) son pagos a cuenta, no gasto."]);
        AuditLog::registrar('crear', $compra, "Compra con los cargos de MercadoLibre {$mes}");
        return $compra;
    }
}
