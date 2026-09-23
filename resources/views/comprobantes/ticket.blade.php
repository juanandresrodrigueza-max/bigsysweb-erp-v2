@php
    $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.');
    $fiscal = $c->esFiscal();
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Ticket {{ $c->numeroFormateado() }}</title>
<style>
  *{box-sizing:border-box} body{font-family:Arial,Helvetica,sans-serif;color:#000;margin:0;padding:12px;font-size:12px;background:#f3f1ec}
  .t{width:80mm;max-width:100%;margin:0 auto;background:#fff;padding:10px 12px;border:1px dashed #bbb}
  .c{text-align:center} .b{font-weight:800} .g{font-size:16px} .s{font-size:10px;color:#444}
  hr{border:0;border-top:1px dashed #999;margin:6px 0}
  table{width:100%;border-collapse:collapse} td{padding:2px 0;vertical-align:top} .r{text-align:right;white-space:nowrap}
  .btn{position:fixed;top:12px;right:12px;background:#e4003f;color:#fff;border:0;border-radius:999px;padding:10px 18px;font-weight:700;cursor:pointer}
  @media print{.btn{display:none} body{padding:0;background:#fff} .t{border:0}}
</style>
</head>
<body>
<button class="btn" onclick="window.print()">Imprimir</button>
<div class="t">
  <div class="c b g">{{ $b->name }}</div>
  <div class="c s">{{ $b->razon_social }} · CUIT {{ $b->cuit }}<br>{{ $c->location?->address }} {{ $c->location?->city }}<br>{{ $b->condicion_iva }}</div>
  <hr>
  <div class="b">{{ $c->nombreTipo() }} {{ $c->numeroFormateado() }}</div>
  <div class="s">{{ $c->fecha->format('d/m/Y') }} {{ $c->emitido_en?->format('H:i') }} · {{ $c->user?->name }}</div>
  <div class="s">Cliente: {{ $c->contact?->name ?? 'Consumidor final' }}@if($c->contact?->cuit) · {{ $c->contact->cuit }}@endif</div>
  <hr>
  <table>
    @foreach($c->items as $i)
      <tr><td colspan="2">{{ $i->descripcion }}</td></tr>
      <tr><td class="s">{{ rtrim(rtrim(number_format((float) $i->cantidad, 3, ',', '.'), '0'), ',') }} x {{ $fmt($i->precio_unit) }}@if((float) $i->descuento > 0) (−{{ (float) $i->descuento }}%)@endif</td><td class="r">{{ $fmt($i->total) }}</td></tr>
    @endforeach
  </table>
  <hr>
  <table>
    @if($c->tipo === 'FA' || $c->tipo === 'NCA')
      <tr><td>Neto</td><td class="r">{{ $fmt($c->neto - $c->descuento) }}</td></tr>
      <tr><td>IVA</td><td class="r">{{ $fmt($c->iva) }}</td></tr>
    @endif
    @if((float) $c->percepciones > 0)<tr><td>Percepciones</td><td class="r">{{ $fmt($c->percepciones) }}</td></tr>@endif
    <tr><td class="b g">TOTAL</td><td class="r b g">{{ $fmt($c->total) }}</td></tr>
  </table>
  @if(isset($cobro) && $cobro)
    <hr>
    @foreach($cobro->medios as $m)<div>{{ \App\Models\Cobro::MEDIOS[$m->medio] ?? $m->medio }}: <span style="float:right">{{ $fmt($m->monto) }}</span></div>@endforeach
    @if(($vuelto ?? 0) > 0)<div class="b">Vuelto: <span style="float:right">{{ $fmt($vuelto) }}</span></div>@endif
  @endif
  <hr>
  @if($fiscal && $c->cae)
    <div class="s">CAE {{ $c->cae }} · Vto {{ $c->cae_vto?->format('d/m/Y') }}</div>
  @elseif($c->afip_estado === 'simulado')
    <div class="s c">COMPROBANTE NO VÁLIDO COMO FACTURA (simulado)</div>
  @endif
  <div class="c s" style="margin-top:6px">¡Gracias por su compra!</div>
</div>
<script>if (location.search.includes('auto=1')) setTimeout(() => window.print(), 300)</script>
</body>
</html>
