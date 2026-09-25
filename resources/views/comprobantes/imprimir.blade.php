@php
    $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.');
    $m = $b->marcaImpresion();
    $loc = $c->location;
    $interno = (bool) $c->sin_arca;
    $fiscal = $c->esFiscal() && ! $interno;
    $simulado = $c->afip_estado === 'simulado';
    $pendiente = $c->afip_estado === 'pendiente';
    $qr = $fiscal && $c->cae ? \App\Services\Afip\QrArca::imagen($c) : null;
    $discrimina = $c->def()['letra'] === 'A';
    $estilo = $m['estilo'];
    $marcaAgua = $interno ? 'NO VÁLIDO COMO FACTURA' : ($simulado ? 'SIMULADO' : ($c->tipo === 'PRE' ? 'PRESUPUESTO' : ($c->estado === 'anulado' ? 'ANULADO' : null)));
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $c->nombreTipo() }} {{ $c->numeroFormateado() }}</title>
@include('comprobantes._estilos')
</head>
<body>
<button class="btn" onclick="window.print()">Imprimir / PDF</button>
<div class="hoja">
  @if($marcaAgua)<div class="marca-agua">{{ $marcaAgua }}</div>@endif
  <table class="cab {{ $estilo === 'banda' ? 'banda' : ($estilo === 'minimo' ? 'minimo' : 'linea') }}"><tr>
    <td>@include('comprobantes._emisor')</td>
    <td class="letra"><b>{{ $interno ? 'X' : ($c->def()['letra'] ?: 'X') }}</b>@if($fiscal)<small>COD. {{ str_pad($c->def()['afip'], 3, '0', STR_PAD_LEFT) }}</small>@endif</td>
    <td>
      <div class="tipo">{{ strtoupper($c->nombreTipo()) }}</div>
      <div class="num">N° {{ $c->numeroFormateado() ?? 'BORRADOR' }}</div>
      <div class="fiscal">
        Fecha: {{ $c->fecha->format('d/m/Y') }}<br>
        @if($c->fecha_vto && $c->esFactura())Vencimiento: {{ $c->fecha_vto->format('d/m/Y') }}<br>@endif
        CUIT: {{ $b->cuit }}<br>
        @if($b->iibb)Ingresos Brutos: {{ $b->iibb }}<br>@endif
        @if($b->inicio_actividades)Inicio de actividades: {{ \Carbon\Carbon::parse($b->inicio_actividades)->format('d/m/Y') }}<br>@endif
        Punto de venta: {{ str_pad($c->punto_venta, 4, '0', STR_PAD_LEFT) }}
        @if($c->origen)<br>Ref: {{ $c->origen->nombreTipo() }} {{ $c->origen->numeroFormateado() }}@endif
      </div>
    </td>
  </tr></table>

  <table class="cli linea">
    @php $rc = $c->receptor ?? []; @endphp
    <tr><td><b>Cliente:</b> {{ $c->contact?->name ?? ($rc['nombre'] ?? 'Consumidor Final') }}</td><td><b>CUIT/DNI:</b> {{ $c->contact?->cuit ?? $c->contact?->document ?? ($rc['documento'] ?? '-') }}</td></tr>
    <tr><td><b>Domicilio:</b> {{ trim(($c->contact?->address ?? ($rc['address'] ?? '')) . ' ' . ($c->contact?->city ?? ($rc['city'] ?? ''))) ?: '-' }}</td><td><b>Cond. IVA:</b> {{ $c->contact?->condicion_iva ?? ($rc['condicion_iva'] ?? 'Consumidor Final') }}</td></tr>
    <tr><td><b>Cond. venta:</b> {{ $c->condicion === 'contado' ? 'Contado' : 'Cuenta corriente' }}@if($c->condicion !== 'contado' && $c->contact?->dias_pago) · {{ $c->contact->dias_pago }} días @endif @if($c->es_acopio)<span class="badge">ACOPIO</span>@endif</td>
        <td>@if($m['mostrar']['vendedor'] && $c->vendedor)<b>Vendedor:</b> {{ $c->vendedor->nombre }}@endif</td></tr>
    @if($c->esExportacion())<tr><td colspan="2"><b>País:</b> {{ config('arca_paises.paises.' . ($c->contact?->pais_codigo ?? ''), $c->contact?->pais_codigo ?? '-') }} @if($c->contact?->id_impositivo)· <b>Id. fiscal:</b> {{ $c->contact->id_impositivo }}@endif @if(($c->exportacion['incoterm'] ?? null) && (int) ($c->exportacion['tipo_expo'] ?? 1) === 1)· <b>Incoterm:</b> {{ $c->exportacion['incoterm'] }}@endif @if($c->exportacion['permiso_embarque'] ?? null)· <b>Permiso de embarque:</b> {{ $c->exportacion['permiso_embarque'] }}@endif</td></tr>@endif
  </table>

  <table class="items">
    <thead><tr>@if($m['mostrar']['codigo'])<th>Código</th>@endif<th>Descripción</th><th class="r">Cant.</th><th class="r">P. unit.</th>@if($m['mostrar']['bonificacion'])<th class="r">Bonif.</th>@endif @if($discrimina)<th class="r">IVA</th>@endif<th class="r">Subtotal</th></tr></thead>
    <tbody>
      @foreach($c->items as $i)
      <tr>@if($m['mostrar']['codigo'])<td>{{ $i->product?->sku }}</td>@endif<td>{{ $i->descripcion }}</td><td class="r">{{ rtrim(rtrim(number_format($i->cantidad, 3, ',', '.'), '0'), ',') }} {{ $i->unidad }}</td>
        <td class="r">{{ $fmt($discrimina ? $i->precio_unit : $i->precio_unit * (1 + $i->alicuota_iva/100)) }}</td>@if($m['mostrar']['bonificacion'])<td class="r">{{ $i->descuento > 0 ? rtrim(rtrim(number_format((float) $i->descuento, 2, ',', ''), '0'), ',') . '%' : '' }}</td>@endif
        @if($discrimina)<td class="r">{{ rtrim(rtrim(number_format((float) $i->alicuota_iva, 2, ',', ''), '0'), ',') }}%</td>@endif
        <td class="r">{{ $fmt($discrimina ? $i->neto : $i->total) }}</td></tr>
      @endforeach
    </tbody>
  </table>

  <table class="tot">
    @if($discrimina)
      <tr><td>Neto gravado</td><td class="r">{{ $fmt($c->neto) }}</td></tr>
      <tr><td>IVA</td><td class="r">{{ $fmt($c->iva) }}</td></tr>
    @else
      <tr><td>Subtotal</td><td class="r">{{ $fmt($c->neto + $c->iva) }}</td></tr>
    @endif
    @foreach($c->impuestos as $t)<tr><td>{{ \App\Models\ComprobanteImpuesto::descripcion($t->tipo) }} {{ rtrim(rtrim(number_format((float) $t->alicuota, 2, ',', ''), '0'), ',') }}%</td><td class="r">{{ $fmt($t->monto) }}</td></tr>@endforeach
    @if($c->percepciones > 0 && $c->impuestos->isEmpty())<tr><td>Percepciones</td><td class="r">{{ $fmt($c->percepciones) }}</td></tr>@endif
    @if($c->esExportacion())<tr><td colspan="2" style="font-size:9.5px;color:#6f6a62">Operación de exportación · exenta de IVA (art. 8 inc. d, Ley de IVA)</td></tr>@endif
    <tr class="g"><td>TOTAL</td><td class="r">{{ $fmt($c->total) }}</td></tr>
    @if(($c->moneda ?? 'ARS') !== 'ARS')<tr><td>Moneda {{ $c->moneda }} · cotización {{ number_format((float) $c->cotizacion, 2, ',', '.') }}</td><td class="r">{{ $c->moneda }} {{ number_format((float) $c->total_me, 2, ',', '.') }}</td></tr>@endif
  </table>
  @if(! $discrimina && $fiscal && in_array($c->def()['letra'], ['B', 'C'], true) && (float) $c->iva > 0)
  <div class="caja">Régimen de Transparencia Fiscal al Consumidor (Ley 27.743) · IVA contenido: {{ $fmt($c->iva) }}</div>
  @endif

  @if($c->tipo === 'REM' && ($c->transportista || $c->patente || $c->domicilio_entrega || $c->bultos || $c->cot))
  <div class="caja"><table>
    <tr><td><b>Entrega en:</b> {{ $c->domicilio_entrega ?: trim(($c->contact?->address ?? '') . ' ' . ($c->contact?->city ?? '')) }}</td><td><b>Transportista:</b> {{ $c->transportista ?: '-' }} @if($c->transportista_cuit)· CUIT {{ $c->transportista_cuit }}@endif</td></tr>
    <tr><td><b>Patente:</b> {{ $c->patente ?: '-' }} · <b>Bultos:</b> {{ $c->bultos ?: '-' }} · <b>Peso:</b> {{ $c->peso_kg ? number_format((float) $c->peso_kg, 2, ',', '.') . ' kg' : '-' }}</td><td><b>COT ARBA:</b> {{ $c->cot ?: 'sin código' }}</td></tr>
  </table></div>
  @endif
  @if($c->tipo === 'REM' && $m['mostrar']['firma_remito'])
  <div class="caja"><table><tr><td style="height:40px;vertical-align:bottom">Recibí conforme: ____________________________</td><td style="vertical-align:bottom">Aclaración y DNI: ____________________________</td></tr></table></div>
  @endif
  @if($m['mostrar']['saldo'] && $c->contact && $c->esFactura() && $c->condicion !== 'contado')
  <div class="caja">Saldo de su cuenta corriente al {{ now()->format('d/m/Y') }}: <b>{{ $fmt($c->contact->balance) }}</b></div>
  @endif
  @if($c->notas)<div class="caja" style="border:0;color:#4a4640">{{ $c->notas }}</div>@endif
  @if($c->tipo === 'PRE')<div class="caja" style="border:0">Presupuesto válido por {{ (int) $m['validez_presupuesto'] }} días.</div>@endif

  <table class="pie"><tr>
    @if($qr)<td style="width:92px"><img src="{{ $qr }}" alt="QR ARCA" style="width:82px;height:82px"></td>@endif
    <td>@if($fiscal && $c->cae)Comprobante Autorizado · CAE: <b>{{ $c->cae }}</b> · Vto CAE: {{ $c->cae_vto?->format('d/m/Y') }}@elseif($fiscal && $pendiente)<span class="badge">PENDIENTE DE CAE · ARCA no respondió al emitir; se reintenta automáticamente. No válido como factura hasta que tenga CAE.</span>@elseif($fiscal && $simulado)<span class="badge">SIN CAE · comprobante simulado, no válido como factura</span>@elseif($fiscal && $c->manual)Comprobante manual de talonario @if($c->cai)· CAI: <b>{{ $c->cai }}</b>@if($c->cai_vto) · Vto CAI: {{ $c->cai_vto->format('d/m/Y') }}@endif @endif @elseif($interno)<span class="badge">COMPROBANTE INTERNO · documento no válido como factura, no informado a ARCA</span>@endif</td>
    <td class="r" style="white-space:nowrap">{{ now()->format('d/m/Y H:i') }}</td>
  </tr></table>
  @if(trim((string) $m['pie']) !== '')<div class="leyenda">{!! nl2br(e($m['pie'])) !!}</div>@endif
</div>
</body>
</html>
