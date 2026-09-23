@extends('tienda._layout', ['titulo' => 'Mi cuenta · ' . $b->name])
@section('cuerpo')
@php $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.'); @endphp
<div class="top"><div class="in">
  <div><h1>Hola, {{ $c->name }}</h1><p>Tu cuenta en {{ $b->name }} @if ($c->cuit) · {{ $c->cuit }} @endif</p></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">@if ($cfg['activa'])<a class="btn s" href="/t/{{ $cfg['slug'] }}">Hacer un pedido</a>@endif @if ($b->phone)<a class="btn s" href="https://wa.me/{{ preg_replace('/\D/', '', $b->phone) }}">WhatsApp</a>@endif</div>
</div></div>
<div class="wrap" style="display:grid;gap:14px">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px">
    <div class="card"><div class="muted">Saldo</div><div style="font-size:22px;font-weight:800;color:{{ $c->balance > 0 ? '#e4003f' : '#146c3d' }}">{{ $fmt($c->balance) }}</div><div class="muted">{{ $c->balance > 0 ? 'a pagar' : ($c->balance < 0 ? 'a tu favor' : 'al día') }}</div></div>
    <div class="card"><div class="muted">Comprobantes pendientes</div><div style="font-size:22px;font-weight:800">{{ $pendientes->count() }}</div></div>
    @if ($fcfg['activo']) <div class="card"><div class="muted">Tus puntos</div><div style="font-size:22px;font-weight:800;color:#4f3089">★ {{ rtrim(rtrim(number_format((float) $c->puntos, 2, ',', '.'), '0'), ',') }}</div><div class="muted">valen {{ $fmt($puntosPesos) }} de descuento</div></div> @endif
    <div class="card"><div class="muted">Condiciones</div><div style="font-weight:700">Lista {{ $c->lista_precios }} · {{ $c->dias_pago }} días</div>@if ($c->descuento)<div class="muted">{{ $c->descuento }}% de descuento</div>@endif</div>
  </div>

  @if ($pendientes->count())
  <div class="card"><h3 style="margin:0 0 8px">Para pagar</h3>
    <table>@foreach ($pendientes as $x) <tr><td><b>{{ $x->nombreTipo() }} {{ $x->numeroFormateado() }}</b><div class="muted">{{ $x->fecha->format('d/m/Y') }} @if ($x->fecha_vto) · vence {{ $x->fecha_vto->format('d/m/Y') }} @endif</div></td><td class="r"><b>{{ $fmt($x->saldo) }}</b></td><td class="r"><a class="btn g" style="padding:8px 14px" href="/portal/{{ $c->portal_token }}/pagar/{{ $x->id }}">Pagar</a></td></tr> @endforeach</table>
  </div>
  @endif

  <div class="card"><h3 style="margin:0 0 8px">Comprobantes</h3>
    <table>@forelse ($comps as $x) <tr><td>{{ $x->nombreTipo() }} <b>{{ $x->numeroFormateado() }}</b><div class="muted">{{ $x->fecha->format('d/m/Y') }}</div></td><td class="r">{{ $fmt($x->total) }}</td><td class="r"><a href="{{ $x->urlPublica() }}">Ver</a> · <a href="{{ $x->urlPublica() }}/pdf">PDF</a></td></tr> @empty <tr><td class="muted">Todavía no hay comprobantes.</td></tr> @endforelse</table>
  </div>

  <div class="card"><h3 style="margin:0 0 8px">Cuenta corriente</h3>
    <table>@forelse ($cc as $m) <tr><td>{{ $m->concepto }}<div class="muted">{{ $m->fecha->format('d/m/Y') }}</div></td><td class="r" style="color:#e4003f">{{ (float) $m->debe > 0 ? $fmt($m->debe) : '' }}</td><td class="r" style="color:#146c3d">{{ (float) $m->haber > 0 ? $fmt($m->haber) : '' }}</td></tr> @empty <tr><td class="muted">Sin movimientos.</td></tr> @endforelse</table>
  </div>

  @if ($pedidos->count())
  <div class="card"><h3 style="margin:0 0 8px">Tus pedidos online</h3>
    <table>@foreach ($pedidos as $p) <tr><td><b>{{ $p->numeroFormateado() }}</b> <span class="tag">{{ \App\Models\PedidoWeb::ESTADOS[$p->estado] ?? $p->estado }}</span><div class="muted">{{ $p->created_at->format('d/m/Y H:i') }} · {{ count($p->items) }} ítem/s</div></td><td class="r">{{ $fmt($p->total) }}</td><td class="r"><a href="{{ $p->urlPublica() }}">Ver</a></td></tr> @endforeach</table>
  </div>
  @endif
  <p class="muted">Este link es tu acceso personal: no lo compartas. Si tenés dudas, escribinos.</p>
</div>
@endsection
