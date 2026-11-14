@extends('tienda._layout', ['titulo' => 'Pedido enviado'])
@section('cuerpo')
<div class="top"><div class="in"><div><h1>{{ $cfg['nombre'] }}</h1><p>{{ $mesa->nombre }}</p></div></div></div>
<div class="wrap" style="max-width:560px;display:grid;gap:14px">
  <div class="ok"><div style="font-size:18px">{{ $confirma ? '¡Pedido enviado! El mozo lo confirma y sale a cocina.' : '¡Pedido enviado a cocina!' }}</div><div style="font-weight:400;font-size:13px">{{ count($items) }} ítem{{ count($items) > 1 ? 's' : '' }} en camino. Si necesitás algo más, volvé a pedir desde el QR o llamá al mozo.</div></div>
  @if ($codigo)
  {{-- Fase 95: el código para que otro celular se sume a la misma cuenta. --}}
  <div class="card" style="text-align:center" data-codigo="{{ $codigo }}"><div class="muted">Código de tu mesa</div><div style="font-size:34px;font-weight:900;letter-spacing:.3em;color:var(--c)">{{ $codigo }}</div><div class="muted">Si alguien más de la mesa escanea el QR, con este código pide a la misma cuenta.</div></div>
  @endif
  <a class="btn p" href="/m/{{ $slug }}?mesa={{ $mesaRef }}">Ver lo que pidieron o pedir algo más</a>
</div>
@endsection
