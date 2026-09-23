@php
    $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.');
    $interno = (bool) $c->sin_arca;
    $fiscal = $c->esFiscal() && ! $interno;
    $simulado = $c->afip_estado === 'simulado';
    $pendiente = $c->afip_estado === 'pendiente';
    $qr = $fiscal && $c->cae ? \App\Services\Afip\QrArca::imagen($c) : null;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $c->nombreTipo() }} {{ $c->numeroFormateado() }}</title>
<style>
  *{box-sizing:border-box} body{font-family:Montserrat,Arial,Helvetica,sans-serif;color:#1c1a18;margin:0;padding:24px;font-size:12px}
  .hoja{max-width:800px;margin:0 auto;border:1px solid #d6d1ca;border-radius:12px;overflow:hidden;position:relative}
  .marca{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:90px;font-weight:800;color:rgba(228,0,63,.07);transform:rotate(-20deg);pointer-events:none}
  .cab{display:grid;grid-template-columns:1fr 90px 1fr;border-bottom:1px solid #d6d1ca}
  .cab>div{padding:16px}
  .letra{display:flex;flex-direction:column;align-items:center;justify-content:center;border-left:1px solid #d6d1ca;border-right:1px solid #d6d1ca}
  .letra b{font-size:34px;line-height:1} .letra small{font-size:9px;color:#6f6a62}
  .emp{font-size:18px;font-weight:800;color:#e4003f} .tipo{font-size:15px;font-weight:700}
  .num{font-size:13px;font-weight:700;margin-top:4px}
  .cli{padding:12px 16px;border-bottom:1px solid #d6d1ca;display:grid;grid-template-columns:1fr 1fr;gap:6px}
  table{width:100%;border-collapse:collapse} th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:#6f6a62;padding:8px 10px;border-bottom:1px solid #d6d1ca;background:#faf9f7}
  td{padding:7px 10px;border-bottom:1px solid #eee} .r{text-align:right} .tot{padding:12px 16px;display:flex;justify-content:flex-end}
  .tot table{width:260px} .tot td{border:0;padding:3px 6px} .tot .g td{font-weight:800;font-size:14px;border-top:1px solid #d6d1ca}
  .pie{padding:12px 16px;border-top:1px solid #d6d1ca;display:flex;justify-content:space-between;font-size:10px;color:#6f6a62}
  .badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#fde8ee;color:#e4003f;font-weight:700;font-size:10px}
  .btn{position:fixed;top:12px;right:12px;background:#e4003f;color:#fff;border:0;border-radius:999px;padding:10px 18px;font-weight:700;cursor:pointer}
  @media print{.btn{display:none} body{padding:0}}
</style>
</head>
<body>
<button class="btn" onclick="window.print()">Imprimir / PDF</button>
<div class="hoja">
  @if($interno)<div class="marca">NO VÁLIDO COMO FACTURA</div>@elseif($simulado)<div class="marca">SIMULADO</div>@elseif($c->tipo === 'PRE')<div class="marca">PRESUPUESTO</div>@elseif($c->estado === 'anulado')<div class="marca">ANULADO</div>@endif
  <div class="cab">
    <div>
      <div class="emp">{{ $b->razon_social ?? $b->name }}</div>
      <div>{{ $b->name }}</div>
      <div>{{ $c->location?->address ?? '' }} {{ $c->location?->city ? '· ' . $c->location->city : '' }}</div>
      <div>Tel: {{ $b->phone }} · {{ $b->email }}</div>
      <div><b>{{ $b->condicion_iva }}</b></div>
    </div>
    <div class="letra"><b>{{ $interno ? 'X' : $c->def()['letra'] }}</b>@if($fiscal)<small>COD. {{ str_pad($c->def()['afip'], 3, '0', STR_PAD_LEFT) }}</small>@endif</div>
    <div>
      <div class="tipo">{{ strtoupper($c->nombreTipo()) }}</div>
      <div class="num">N° {{ $c->numeroFormateado() ?? 'BORRADOR' }}</div>
      <div>Fecha: {{ $c->fecha->format('d/m/Y') }}</div>
      @if($c->fecha_vto && $c->esFactura())<div>Vencimiento: {{ $c->fecha_vto->format('d/m/Y') }}</div>@endif
      <div>CUIT: {{ $b->cuit }}</div>
      <div>Punto de venta: {{ str_pad($c->punto_venta, 4, '0', STR_PAD_LEFT) }}</div>
      @if($c->origen)<div>Ref: {{ $c->origen->nombreTipo() }} {{ $c->origen->numeroFormateado() }}</div>@endif
    </div>
  </div>
  <div class="cli">
    <div><b>Cliente:</b> {{ $c->contact?->name ?? 'Consumidor Final' }}</div>
    <div><b>CUIT/DNI:</b> {{ $c->contact?->cuit ?? $c->contact?->document ?? '-' }}</div>
    <div><b>Domicilio:</b> {{ trim(($c->contact?->address ?? '') . ' ' . ($c->contact?->city ?? '')) ?: '-' }}</div>
    @if($c->esExportacion())<div><b>País:</b> {{ config('arca_paises.paises.' . ($c->contact?->pais_codigo ?? ''), $c->contact?->pais_codigo ?? '-') }} @if($c->contact?->id_impositivo)· <b>Id. fiscal:</b> {{ $c->contact->id_impositivo }}@endif @if(($c->exportacion['incoterm'] ?? null) && (int) ($c->exportacion['tipo_expo'] ?? 1) === 1)· <b>Incoterm:</b> {{ $c->exportacion['incoterm'] }}@endif @if($c->exportacion['permiso_embarque'] ?? null)· <b>Permiso de embarque:</b> {{ $c->exportacion['permiso_embarque'] }}@endif</div>@endif
    <div><b>Cond. IVA:</b> {{ $c->contact?->condicion_iva ?? 'Consumidor Final' }} &nbsp; <b>Cond. venta:</b> {{ $c->condicion === 'contado' ? 'Contado' : 'Cuenta corriente' }} @if($c->es_acopio)<span class="badge">ACOPIO</span>@endif</div>
  </div>
  <table>
    <thead><tr><th>Código</th><th>Descripción</th><th class="r">Cant.</th><th class="r">P. unit.</th><th class="r">Bonif.</th>@if($c->def()['letra']==='A')<th class="r">IVA</th>@endif<th class="r">Subtotal</th></tr></thead>
    <tbody>
      @foreach($c->items as $i)
      <tr><td>{{ $i->product?->sku }}</td><td>{{ $i->descripcion }}</td><td class="r">{{ rtrim(rtrim(number_format($i->cantidad, 3, ',', '.'), '0'), ',') }} {{ $i->unidad }}</td>
        <td class="r">{{ $fmt($c->def()['letra']==='A' ? $i->precio_unit : $i->precio_unit * (1 + $i->alicuota_iva/100)) }}</td><td class="r">{{ $i->descuento > 0 ? $i->descuento . '%' : '' }}</td>
        @if($c->def()['letra']==='A')<td class="r">{{ $i->alicuota_iva }}%</td>@endif
        <td class="r">{{ $fmt($c->def()['letra']==='A' ? $i->neto : $i->total) }}</td></tr>
      @endforeach
    </tbody>
  </table>
  <div class="tot"><table>
    @if($c->def()['letra']==='A')
      <tr><td>Neto gravado</td><td class="r">{{ $fmt($c->neto) }}</td></tr>
      <tr><td>IVA</td><td class="r">{{ $fmt($c->iva) }}</td></tr>
    @else
      <tr><td>Subtotal</td><td class="r">{{ $fmt($c->neto + $c->iva) }}</td></tr>
    @endif
    @foreach($c->impuestos as $t)<tr><td>{{ \App\Models\ComprobanteImpuesto::descripcion($t->tipo) }} {{ rtrim(rtrim(number_format((float) $t->alicuota, 2, ',', ''), '0'), ',') }}%</td><td class="r">{{ $fmt($t->monto) }}</td></tr>@endforeach
    @if($c->percepciones > 0 && $c->impuestos->isEmpty())<tr><td>Percepciones</td><td class="r">{{ $fmt($c->percepciones) }}</td></tr>@endif
    @if($c->esExportacion())<tr><td colspan="2" style="font-size:10px;color:#6f6a62">Operación de exportación · exenta de IVA (art. 8 inc. d, Ley de IVA)</td></tr>@endif
    <tr class="g"><td>TOTAL</td><td class="r">{{ $fmt($c->total) }}</td></tr>
    @if(($c->moneda ?? 'ARS') !== 'ARS')<tr><td>Moneda {{ $c->moneda }} · cotización {{ number_format((float) $c->cotizacion, 2, ',', '.') }}</td><td class="r">{{ $c->moneda }} {{ number_format((float) $c->total_me, 2, ',', '.') }}</td></tr>@endif
  </table></div>
  @if($c->tipo === 'REM' && ($c->transportista || $c->patente || $c->domicilio_entrega || $c->bultos || $c->cot))
  <div style="margin:0 16px 12px;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:11px;display:grid;grid-template-columns:1fr 1fr;gap:2px 12px">
    <div><b>Entrega en:</b> {{ $c->domicilio_entrega ?: trim(($c->contact?->address ?? '') . ' ' . ($c->contact?->city ?? '')) }}</div>
    <div><b>Transportista:</b> {{ $c->transportista ?: '-' }} @if($c->transportista_cuit)· CUIT {{ $c->transportista_cuit }}@endif</div>
    <div><b>Patente:</b> {{ $c->patente ?: '-' }} · <b>Bultos:</b> {{ $c->bultos ?: '-' }} · <b>Peso:</b> {{ $c->peso_kg ? number_format((float) $c->peso_kg, 2, ',', '.') . ' kg' : '-' }}</div>
    <div><b>COT ARBA:</b> {{ $c->cot ?: 'sin código' }}</div>
  </div>
  @endif
  @if($c->notas)<div style="padding:0 16px 12px;color:#6f6a62">{{ $c->notas }}</div>@endif
  <div class="pie">
    <div style="display:flex;align-items:center;gap:10px">@if($qr)<img src="{{ $qr }}" alt="QR ARCA" style="width:82px;height:82px">@endif<span>@if($fiscal && $c->cae)Comprobante Autorizado · CAE: <b>{{ $c->cae }}</b> · Vto CAE: {{ $c->cae_vto?->format('d/m/Y') }}@elseif($fiscal && $pendiente)<span class="badge">PENDIENTE DE CAE · ARCA no respondió al emitir; se reintenta automáticamente. No válido como factura hasta que tenga CAE.</span>@elseif($fiscal && $simulado)<span class="badge">SIN CAE · comprobante simulado, no válido como factura</span>@elseif($interno)<span class="badge">COMPROBANTE INTERNO · documento no válido como factura, no informado a ARCA</span>@elseif($c->tipo==='PRE')Presupuesto válido por 7 días.@endif</span></div>
    <div>BigSysWeb · {{ now()->format('d/m/Y H:i') }}</div>
  </div>
</div>
</body>
</html>
