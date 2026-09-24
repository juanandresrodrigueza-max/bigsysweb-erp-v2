@php $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.'); $m = $b->marcaImpresion(); $loc = \App\Models\BusinessLocation::find($r->business_location_id); @endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Recibo {{ $r->numeroFormateado() }}</title>
@include('comprobantes._estilos')
<style>.sec{padding:10px 16px;border-bottom:1px solid #d6d1ca} h4{margin:0 0 6px;font-size:9.5px;text-transform:uppercase;letter-spacing:.08em;color:{{ $m['color_secundario'] }}} .sec td{padding:4px 0;border-bottom:1px solid #eee} .total{padding:12px 16px;text-align:right;font-size:16px;font-weight:800;color:{{ $m['color_primario'] }}}</style>
</head>
<body>
<button class="btn" onclick="window.print()">Imprimir / PDF</button>
<div class="hoja">
  @if($r->estado === 'anulado')<div class="marca-agua">ANULADO</div>@endif
  <table class="cab {{ $m['estilo'] === 'banda' ? 'banda' : ($m['estilo'] === 'minimo' ? 'minimo' : 'linea') }}"><tr>
    <td>@include('comprobantes._emisor')</td>
    <td class="r"><div class="tipo">RECIBO</div><div class="num">N° {{ $r->numeroFormateado() }}</div><div class="fiscal">Fecha: {{ $r->fecha->format('d/m/Y') }}<br>CUIT: {{ $b->cuit }}@if($b->iibb)<br>Ingresos Brutos: {{ $b->iibb }}@endif</div></td>
  </tr></table>
  <div class="sec"><h4>Recibimos de</h4><b>{{ $r->contact->name }}</b> · {{ $r->contact->cuit ?? $r->contact->document ?? '' }}</div>
  <div class="sec"><h4>Medios de pago</h4><table>
    @foreach($r->medios as $md)<tr><td>{{ \App\Models\Cobro::MEDIOS[$md->medio] ?? $md->medio }} {{ $md->referencia ? "· {$md->referencia}" : '' }} @if($md->datos)<small>({{ collect($md->datos)->filter()->map(fn($v,$k)=>"$k: $v")->implode(', ') }})</small>@endif</td><td class="r">{{ $fmt($md->monto) }}</td></tr>@endforeach
  </table></div>
  <div class="sec"><h4>Aplicado a</h4><table>
    @forelse($r->imputaciones as $i)<tr><td>{{ $i->comprobante->nombreTipo() }} {{ $i->comprobante->numeroFormateado() }} del {{ $i->comprobante->fecha->format('d/m/Y') }}</td><td class="r">{{ $fmt($i->monto) }}</td></tr>@empty<tr><td>Sin imputar</td><td></td></tr>@endforelse
    @if($r->a_cuenta > 0)<tr><td><i>A cuenta</i></td><td class="r">{{ $fmt($r->a_cuenta) }}</td></tr>@endif
  </table></div>
  <div class="total">TOTAL {{ $fmt($r->total) }}</div>
  @if($m['mostrar']['saldo'])<div class="caja">Saldo de su cuenta corriente al {{ now()->format('d/m/Y') }}: <b>{{ $fmt($r->contact->balance) }}</b></div>@endif
  @if($r->notas)<div class="caja" style="border:0;color:#4a4640">{{ $r->notas }}</div>@endif
  @if(trim((string) $m['pie']) !== '')<div class="leyenda">{!! nl2br(e($m['pie'])) !!}</div>@endif
</div>
</body>
</html>
