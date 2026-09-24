@php $m = $marca; $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.'); $cant = fn($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ','); @endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $catalogo->nombre }}</title>
<style>
  @page{margin:16mm 12mm} body{font-family:DejaVu Sans,Arial,sans-serif;color:#1c1a18;font-size:10px;margin:0}
  table{width:100%;border-collapse:collapse}
  .cab td{background:{{ $m['color_primario'] }};color:{{ $m['texto_primario'] }};padding:12px 14px;vertical-align:middle}
  .cab img{max-height:46px;max-width:150px;background:#fff;padding:4px}
  .tit{font-size:16px;font-weight:bold} .sub{font-size:9px}
  .nota{margin:8px 0;padding:6px 10px;border-left:3px solid {{ $m['color_primario'] }};background:{{ $m['suave'] }}}
  h2{font-size:11.5px;color:{{ $m['titulo'] }};margin:12px 0 4px;border-bottom:1.5px solid {{ $m['color_secundario'] }};padding-bottom:2px}
  .it td{padding:4px 6px;border-bottom:1px solid #eee} .it tr:nth-child(even) td{background:{{ $m['suave'] }}}
  .r{text-align:right;white-space:nowrap} .cod{color:#6f6a62} .precio{font-weight:bold;color:{{ $m['acento'] }}}
  .tag{font-size:8px;font-weight:bold;color:{{ $m['texto_secundario'] }};background:{{ $m['color_secundario'] }};padding:1px 4px}
  .pie{margin-top:14px;font-size:8.5px;color:#6f6a62;text-align:center}
</style>
</head>
<body>
<table class="cab"><tr>
  @if($m['logo_uri'])<td style="width:160px"><img src="{{ $m['logo_uri'] }}" alt=""></td>@endif
  <td><div class="tit">{{ $catalogo->nombre }}</div><div class="sub">{{ $empresa->razon_social ?: $empresa->name }} · {{ $aclaracion }} · {{ now()->format('d/m/Y') }}</div></td>
  @if($cliente)<td class="r"><div class="sub">Precios para</div><b>{{ $cliente->name }}</b></td>@endif
</tr></table>
@if($catalogo->nota)<div class="nota">{{ $catalogo->nota }}</div>@endif
@foreach($rubros as $r)
<h2>{{ $r['nombre'] }}</h2>
<table class="it">
  @foreach($r['items'] as $p)
  <tr>
    @if($catalogo->mostrar_codigo)<td class="cod" style="width:70px">{{ $p['sku'] }}</td>@endif
    <td>{{ $p['nombre'] }} @if($p['especial'])<span class="tag">ESPECIAL{{ $p['descuento'] > 0 ? ' ' . $cant($p['descuento']) . '%' : '' }}</span>@endif @if($p['desc_cant'])<span class="cod">· {{ $cant($p['desc_cant']['pct']) }}% x {{ $cant($p['desc_cant']['min']) }}+</span>@endif @if($p['sin_stock'])<span class="cod">· sin stock</span>@endif</td>
    @if($catalogo->mostrar_stock)<td class="r cod">{{ $p['stock'] !== null ? $cant($p['stock']) : '' }}</td>@endif
    <td class="r cod" style="width:40px">{{ $p['unidad'] }}</td>
    <td class="r precio" style="width:90px">{{ $fmt($p['precio']) }}</td>
  </tr>
  @endforeach
</table>
@endforeach
<div class="pie">{{ $empresa->name }}@if($empresa->phone) · {{ $empresa->phone }}@endif @if($empresa->email) · {{ $empresa->email }}@endif @foreach($m['lineas_extra'] as $l) · {{ $l }}@endforeach<br>Precios sujetos a cambio sin previo aviso. {{ $m['pie'] }}</div>
</body>
</html>
