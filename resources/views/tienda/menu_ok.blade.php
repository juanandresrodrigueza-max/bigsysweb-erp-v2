@extends('tienda._layout', ['titulo' => 'Pedido enviado'])
@section('cuerpo')
<div class="top"><div class="in"><div><h1>{{ $cfg['nombre'] }}</h1><p>{{ $mesa->nombre }}</p></div></div></div>
<div class="wrap" style="max-width:560px;display:grid;gap:14px">
  <div class="ok"><div style="font-size:18px">¡Pedido enviado a cocina!</div><div style="font-weight:400;font-size:13px">{{ count($items) }} ítem{{ count($items) > 1 ? 's' : '' }} en camino. Si necesitás algo más, volvé a pedir desde el QR o llamá al mozo.</div></div>
  <a class="btn p" href="/m/{{ $slug }}?mesa={{ $mesa->id }}">Pedir algo más</a>
</div>
@endsection
