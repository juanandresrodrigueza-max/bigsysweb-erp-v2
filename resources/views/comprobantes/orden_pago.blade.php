@php $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.'); $m = $b->marcaImpresion(); $loc = \App\Models\BusinessLocation::find($p->business_location_id); @endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Orden de pago {{ $p->numeroFormateado() }}</title>
@include('comprobantes._estilos')
<style>.sec{padding:10px 16px;border-bottom:1px solid #d6d1ca} h4{margin:0 0 6px;font-size:9.5px;text-transform:uppercase;letter-spacing:.08em;color:{{ $m['color_secundario'] }}} .sec td{padding:4px 0;border-bottom:1px solid #eee} .total{padding:12px 16px;text-align:right;font-size:16px;font-weight:800;color:{{ $m['color_primario'] }}} .firma td{padding:40px 16px 16px;font-size:10px;color:#6f6a62;text-align:center}</style>
</head>
<body>
<button class="btn" onclick="window.print()">Imprimir / PDF</button>
<div class="hoja">
  @if($p->estado === 'anulado')<div class="marca-agua">ANULADA</div>@endif
  <table class="cab {{ $m['estilo'] === 'banda' ? 'banda' : ($m['estilo'] === 'minimo' ? 'minimo' : 'linea') }}"><tr>
    <td>@include('comprobantes._emisor')</td>
    <td class="r"><div class="tipo">ORDEN DE PAGO</div><div class="num">N° {{ $p->numeroFormateado() }}</div><div class="fiscal">Fecha: {{ $p->fecha->format('d/m/Y') }}<br>CUIT: {{ $b->cuit }}</div></td>
  </tr></table>
  <div class="sec"><h4>Pagamos a</h4><b>{{ $p->contact->name }}</b> · {{ $p->contact->cuit ?? '' }}</div>
  <div class="sec"><h4>Medios de pago</h4><table>
    @foreach($p->medios as $md)<tr><td>{{ \App\Models\Pago::MEDIOS[$md->medio] ?? $md->medio }}@if($md->cheque) · cheque {{ $md->cheque->numero }} {{ $md->cheque->banco }} vto {{ $md->cheque->fecha_pago->format('d/m/Y') }}@endif{{ $md->referencia ? " · {$md->referencia}" : '' }}</td><td class="r">{{ $fmt($md->monto) }}</td></tr>@endforeach
  </table></div>
  @if($p->retenciones->count())
  <div class="sec"><h4>Retenciones practicadas</h4><table>
    @foreach($p->retenciones as $rt)<tr><td>{{ \App\Models\Retencion::TIPOS[$rt->tipo] ?? $rt->tipo }} {{ $rt->jurisdiccion }} · base {{ $fmt($rt->base) }} · {{ $rt->alicuota }}% @if($rt->certificado)· cert. {{ $rt->certificado }}@endif</td><td class="r">{{ $fmt($rt->monto) }}</td></tr>@endforeach
  </table></div>
  @endif
  <div class="sec"><h4>Aplicado a</h4><table>
    @forelse($p->imputaciones as $i)<tr><td>{{ $i->comprobante->nombreTipo() }} {{ $i->comprobante->numeroFormateado() }} del {{ $i->comprobante->fecha->format('d/m/Y') }}</td><td class="r">{{ $fmt($i->monto) }}</td></tr>@empty<tr><td>Sin imputar</td><td></td></tr>@endforelse
    @if($p->a_cuenta > 0)<tr><td><i>A cuenta</i></td><td class="r">{{ $fmt($p->a_cuenta) }}</td></tr>@endif
  </table></div>
  <div class="total">TOTAL {{ $fmt($p->total) }}</div>
  <table class="firma"><tr><td>______________________<br>Recibí conforme</td><td>______________________<br>Autorizó</td></tr></table>
  @if(trim((string) $m['pie']) !== '')<div class="leyenda">{!! nl2br(e($m['pie'])) !!}</div>@endif
</div>
</body>
</html>
