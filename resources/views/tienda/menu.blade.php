@extends('tienda._layout', ['titulo' => 'Carta · ' . $cfg['nombre']])
@section('cuerpo')
@php $fmt = fn($n) => '$ ' . number_format((float) $n, 0, ',', '.'); @endphp
<div class="top"><div class="in"><div><h1>{{ $cfg['nombre'] }}</h1><p>@if ($mesa) {{ $mesa->nombre }} · elegí y pedí desde acá, llega directo a cocina. @else Nuestra carta. Para pedir, escaneá el QR de tu mesa. @endif </p></div>@if ($cfg['reservas_activas'])<a class="btn s" href="/r/{{ $cfg['slug'] }}">Reservar</a>@endif</div></div>
<div class="wrap">
  @forelse ($cat['rubros'] as $r)
    <div class="rubro">{{ $r['nombre'] }}</div>
    <div class="card" style="padding:4px 16px">
      @foreach ($r['items'] as $p)
        <div style="display:flex;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid #f2efea">
          @if ($p['imagen']) <img src="{{ $p['imagen'] }}" style="width:56px;height:56px;border-radius:10px;object-fit:cover" alt=""> @endif
          <div style="flex:1;min-width:0"><div style="font-weight:700">{{ $p['nombre'] }}</div>@if ($p['descripcion'])<div class="muted">{{ $p['descripcion'] }}</div>@endif</div>
          <div style="font-weight:800;color:var(--c);white-space:nowrap">{{ $fmt($p['precio']) }}</div>
          @if ($mesa) <div class="qty" style="margin:0"><button type="button" onclick="cambiar({{ $p['id'] }}, -1)">−</button><span id="q{{ $p['id'] }}">0</span><button type="button" onclick="cambiar({{ $p['id'] }}, 1)">+</button></div> @endif
        </div>
      @endforeach
    </div>
  @empty
    <div class="card">La carta todavía no está cargada.</div>
  @endforelse
</div>
@if ($mesa)
<div class="carrito" id="carrito"><div class="in"><div><b id="cTotal">$ 0</b> <span class="muted" id="cItems"></span></div><button class="btn p" onclick="abrir()">Pedir a la mesa →</button></div></div>
<div class="modal" id="modal"><div class="box">
  <h2 style="margin:0 0 6px">Tu pedido · {{ $mesa->nombre }}</h2>
  <table id="resumen"></table>
  <form method="post" action="/m/{{ $cfg['slug'] }}/pedir">@csrf<input type="hidden" name="mesa_id" value="{{ $mesa->id }}"><div id="hidden"></div>
    <label>Tu nombre (opcional)</label><input name="nombre" placeholder="Para que el mozo sepa quién pidió">
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px"><button type="button" class="btn s" onclick="cerrar()">Volver</button><button class="btn p">Enviar a cocina</button></div>
  </form>
</div></div>
<script>
const P = {!! json_encode(collect($cat['rubros'])->flatMap(fn($r) => $r['items'])->mapWithKeys(fn($p) => [$p['id'] => ['n' => $p['nombre'], 'p' => $p['precio']]])) !!};
const fmt = n => '$ ' + Number(n).toLocaleString('es-AR', { maximumFractionDigits: 0 });
let cart = {};
function cambiar(id, d) { cart[id] = Math.max(0, (cart[id] || 0) + d); if (!cart[id]) delete cart[id]; pintar() }
function pintar() { let t = 0, n = 0; Object.keys(P).forEach(id => { const q = cart[id] || 0; const el = document.getElementById('q' + id); if (el) el.textContent = q; t += P[id].p * q; n += q }); document.getElementById('cTotal').textContent = fmt(t); document.getElementById('cItems').textContent = n ? `· ${n} ítem${n > 1 ? 's' : ''}` : ''; document.getElementById('carrito').classList.toggle('on', n > 0) }
function abrir() { let h = ''; Object.keys(cart).forEach(id => { h += `<tr><td>${P[id].n} <span class="muted">× ${cart[id]}</span></td><td class="r">${fmt(P[id].p * cart[id])}</td></tr>` }); document.getElementById('resumen').innerHTML = h; document.getElementById('hidden').innerHTML = Object.keys(cart).map((id, i) => `<input type="hidden" name="items[${i}][product_id]" value="${id}"><input type="hidden" name="items[${i}][cantidad]" value="${cart[id]}">`).join(''); document.getElementById('modal').classList.add('on') }
function cerrar() { document.getElementById('modal').classList.remove('on') }
</script>
@endif
@endsection
