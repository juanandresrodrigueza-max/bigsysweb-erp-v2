@php $cant = fn($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ','); @endphp
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Pase {{ $t->numeroFormateado() }}</title>
<style>body{font-family:DejaVu Sans,sans-serif;font-size:11px} table{width:100%;border-collapse:collapse} td,th{border-bottom:1px solid #ddd;padding:5px;text-align:left} .r{text-align:right} .dif{color:#c8102e;font-weight:bold}</style></head><body>
<h2 style="margin:0">{{ $b?->name }} · Pase {{ $t->numeroFormateado() }}</h2>
<p>{{ $t->origen?->nombre }} → {{ $t->destino?->nombre }} · enviado {{ $t->enviada_en?->format('d/m/Y H:i') ?? $t->fecha->format('d/m/Y') }} · {{ $estados[$t->estado] ?? $t->estado }}@if($t->recibida_en) · recibido {{ $t->recibida_en->format('d/m/Y H:i') }}@endif</p>
<table><thead><tr><th>Artículo</th><th>Código</th><th class="r">Enviado</th><th class="r">Recibido</th><th class="r">Diferencia</th></tr></thead><tbody>
@foreach($t->items as $i)@php $d = $i->cantidad_recibida !== null ? (float) $i->cantidad_recibida - (float) $i->cantidad : null; @endphp
<tr><td>{{ $i->product?->name }}</td><td>{{ $i->product?->barcode ?: $i->product?->sku }}</td><td class="r">{{ $cant($i->cantidad) }}</td><td class="r">{{ $cant($i->cantidad_recibida) }}</td><td class="r {{ $d ? 'dif' : '' }}">{{ $d === null ? '' : ($d > 0 ? '+' : '') . $cant($d) }}</td></tr>@endforeach
</tbody></table>
@if($t->notas)<p>{{ $t->notas }}</p>@endif
<p style="margin-top:40px">Entregó: ____________________ &nbsp;&nbsp; Recibió: ____________________</p>
</body></html>
