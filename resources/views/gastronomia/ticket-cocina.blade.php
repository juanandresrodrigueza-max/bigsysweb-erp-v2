<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><title>{{ $estacion }} · {{ $c->titulo() }}</title>
<style>@page{size:80mm auto;margin:3mm}body{font-family:Arial,Helvetica,sans-serif;width:74mm;margin:0 auto;font-size:13px;color:#000}h1{font-size:20px;margin:0;text-align:center;text-transform:uppercase}.c{text-align:center}.it{display:flex;gap:6px;margin:6px 0;font-size:16px;font-weight:bold}.q{min-width:28px}.n{font-weight:normal;font-size:13px;margin-left:34px}hr{border:0;border-top:1px dashed #000}@media screen{body{margin-top:12px}}</style></head>
<body onload="window.print()">
<h1>{{ $estacion }}</h1>
<p class="c" style="font-size:18px;font-weight:bold;margin:4px 0">{{ $c->titulo() }}</p>
<p class="c">{{ $c->numeroFormateado() }} · {{ $c->mozo?->name }} · {{ now()->format('d/m H:i') }}</p>
<hr>
@forelse($items as $i)
<div class="it"><span class="q">{{ rtrim(rtrim(number_format((float) $i->cantidad, 2, ',', ''), '0'), ',') }}</span><span>{{ $i->descripcion }}</span></div>
@if($i->notas)<div class="n">** {{ $i->notas }}</div>@endif
@empty
<p class="c">Nada para esta estación.</p>
@endforelse
<hr>
</body></html>
