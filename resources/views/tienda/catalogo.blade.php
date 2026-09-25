@extends('tienda._layout', ['titulo' => $cfg['nombre']])
@section('cuerpo')
@php $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.'); @endphp
<div class="top"><div class="in">
  <div><h1>{{ $cfg['nombre'] }}</h1><p>{{ $cfg['descripcion'] ?: 'Pedí online y te lo preparamos.' }} @if ($contact) · Hola {{ $contact->name }} (tus precios) @endif </p></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    @if ($cfg['whatsapp']) <a class="btn s" href="https://wa.me/{{ preg_replace('/\D/', '', $cfg['whatsapp']) }}" target="_blank">WhatsApp</a> @endif
    @if ($contact) <a class="btn s" href="{{ url('/portal/' . $contact->portal_token) }}">Mi cuenta</a> @endif
  </div>
</div></div>
<div class="wrap">
  <input id="buscar" placeholder="Buscar producto…" style="margin-bottom:6px" oninput="filtrar(this.value)">
  @if ($cfg['minimo_pedido'] > 0) <p class="muted">Pedido mínimo {{ $fmt($cfg['minimo_pedido']) }}. @if ($cfg['envio'] && $cfg['envio_gratis_desde'] > 0) Envío gratis desde {{ $fmt($cfg['envio_gratis_desde']) }}. @endif </p> @endif
  @forelse ($cat['rubros'] as $r)
    <div class="rubro" data-rubro>@if (! empty($r['imagen']))<img src="{{ $r['imagen'] }}" alt="" style="width:28px;height:28px;border-radius:8px;object-fit:cover;vertical-align:middle;margin-right:8px">@endif{{ $r['nombre'] }}</div>
    <div class="grid">
      @foreach ($r['items'] as $p)
        <div class="prod" data-prod data-nombre="{{ mb_strtolower($p['nombre'] . ' ' . $p['sku']) }}">
          @if ($p['imagen']) <img src="{{ $p['imagen'] }}" alt=""> @endif
          <div class="n">{{ $p['nombre'] }}</div>
          @if ($p['descripcion']) <div class="muted">{{ $p['descripcion'] }}</div> @endif
          <div class="pr">{{ $fmt($p['precio']) }} <span class="muted">/ {{ $p['unit'] }}</span></div>
          @if (($p['desc_cant2_pct'] ?? 0) > 0) <span class="tag">−{{ $p['desc_cant2_pct'] }}% llevando {{ rtrim(rtrim(number_format($p['desc_cant2_min'], 2, ',', '.'), '0'), ',') }}+</span> @endif @if ($p['desc_cant_pct'] > 0) <span class="tag">−{{ $p['desc_cant_pct'] }}% llevando {{ rtrim(rtrim(number_format($p['desc_cant_min'], 2, ',', '.'), '0'), ',') }} o más</span> @endif
          @if ($cfg['mostrar_stock']) <span class="tag">{{ $p['sin_stock'] ? 'Sin stock: consultar' : 'En stock' }}</span> @endif
          <div class="qty"><button type="button" onclick="cambiar({{ $p['id'] }}, -1)">−</button><span id="q{{ $p['id'] }}">0</span><button type="button" onclick="cambiar({{ $p['id'] }}, 1)">+</button><span class="muted" style="margin-left:auto" id="s{{ $p['id'] }}"></span></div>
        </div>
      @endforeach
    </div>
  @empty
    <div class="card">Todavía no hay artículos publicados.</div>
  @endforelse
</div>

<div class="carrito" id="carrito"><div class="in"><div><b id="cTotal">$ 0</b> <span class="muted" id="cItems"></span></div><button class="btn p" onclick="abrir()">Hacer el pedido →</button></div></div>

<div class="modal" id="modal"><div class="box">
  <h2 style="margin:0 0 6px">Tu pedido</h2>
  <table id="resumen"></table>
  <form method="post" action="/t/{{ $cfg['slug'] }}/pedir" id="form">
    @csrf
    <div id="hidden"></div>
    @if ($errors->any()) <div class="bad" style="margin-top:8px">{{ $errors->first() }}</div> @endif
    <label>Nombre</label><input name="nombre" required value="{{ old('nombre', $contact?->name) }}">
    <label>Teléfono / WhatsApp</label><input name="telefono" required value="{{ old('telefono', $contact?->phone) }}">
    <label>Email (para el comprobante)</label><input name="email" type="email" value="{{ old('email', $contact?->email) }}">
    <label>Entrega</label>
    <select name="entrega" id="entrega" onchange="recalc()">
      @if ($cfg['retiro']) <option value="retiro">Retiro en el local</option> @endif
      @if ($cfg['envio']) <option value="envio">Envío a domicilio @if ($cfg['costo_envio'] > 0) ({{ $fmt($cfg['costo_envio']) }}@if ($cfg['envio_gratis_desde'] > 0), gratis desde {{ $fmt($cfg['envio_gratis_desde']) }}@endif) @endif</option> @endif
    </select>
    <div id="dirBox"><label>Dirección de entrega</label><input name="direccion" value="{{ old('direccion', $contact?->address) }}"> @if ($cfg['zona_envio']) <p class="muted">Enviamos a: {{ $cfg['zona_envio'] }}</p> @endif </div>
    <label>Forma de pago</label>
    <select name="pago">
      @if ($cfg['pagos']['link'] ?? false) <option value="link">Pago online (MercadoPago)</option> @endif
      @if ($cfg['pagos']['transferencia'] ?? false) <option value="transferencia">Transferencia @if ($cfg['alias']) (alias {{ $cfg['alias'] }}) @endif</option> @endif
      @if ($cfg['pagos']['efectivo'] ?? false) <option value="efectivo">Efectivo al recibir / retirar</option> @endif
      <option value="a_convenir">A convenir</option>
    </select>
    <label>Notas</label><textarea name="notas" rows="2" placeholder="Horario, referencias, aclaraciones…">{{ old('notas') }}</textarea>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px"><button type="button" class="btn s" onclick="cerrar()">Seguir eligiendo</button><button class="btn p" id="enviar">Confirmar pedido</button></div>
  </form>
</div></div>

<script>
const P = {!! json_encode(collect($cat['rubros'])->flatMap(fn($r) => $r['items'])->mapWithKeys(fn($p) => [$p['id'] => ['n' => $p['nombre'], 'p' => $p['precio'], 'u' => $p['unit'], 'dm' => $p['desc_cant_min'], 'dp' => $p['desc_cant_pct']]])) !!};
const CFG = { envio: {{ (float) $cfg['costo_envio'] }}, gratis: {{ (float) $cfg['envio_gratis_desde'] }}, minimo: {{ (float) $cfg['minimo_pedido'] }} };
const fmt = n => '$ ' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
let cart = {}; try { cart = JSON.parse(localStorage.getItem('cart_{{ $cfg['slug'] }}') || '{}') } catch (e) {}
function precio(id, q) { const p = P[id]; return p.dp > 0 && q >= p.dm ? p.p * (1 - p.dp / 100) : p.p }
function cambiar(id, d) { cart[id] = Math.max(0, (cart[id] || 0) + d); if (!cart[id]) delete cart[id]; pintar() }
function pintar() {
  let t = 0, n = 0;
  Object.keys(P).forEach(id => { const q = cart[id] || 0; const el = document.getElementById('q' + id); if (el) el.textContent = q; const s = document.getElementById('s' + id); if (s) s.textContent = q ? fmt(precio(id, q) * q) : ''; t += precio(id, q) * q; n += q });
  document.getElementById('cTotal').textContent = fmt(t); document.getElementById('cItems').textContent = n ? `· ${n} ítem${n > 1 ? 's' : ''}` : '';
  document.getElementById('carrito').classList.toggle('on', n > 0);
  try { localStorage.setItem('cart_{{ $cfg['slug'] }}', JSON.stringify(cart)) } catch (e) {}
  recalc()
}
function recalc() {
  const sub = Object.keys(cart).reduce((a, id) => a + precio(id, cart[id]) * cart[id], 0);
  const envio = document.getElementById('entrega')?.value === 'envio' ? (CFG.gratis > 0 && sub >= CFG.gratis ? 0 : CFG.envio) : 0;
  document.getElementById('dirBox').style.display = document.getElementById('entrega')?.value === 'envio' ? '' : 'none';
  let h = ''; Object.keys(cart).forEach(id => { h += `<tr><td>${P[id].n} <span class="muted">× ${cart[id]}</span></td><td class="r">${fmt(precio(id, cart[id]) * cart[id])}</td></tr>` });
  if (envio) h += `<tr><td>Envío</td><td class="r">${fmt(envio)}</td></tr>`;
  h += `<tr><td><b>Total</b></td><td class="r"><b>${fmt(sub + envio)}</b></td></tr>`;
  document.getElementById('resumen').innerHTML = h;
  document.getElementById('hidden').innerHTML = Object.keys(cart).map((id, i) => `<input type="hidden" name="items[${i}][product_id]" value="${id}"><input type="hidden" name="items[${i}][cantidad]" value="${cart[id]}">`).join('');
  const btn = document.getElementById('enviar'); if (btn) btn.disabled = sub < CFG.minimo || !Object.keys(cart).length;
}
function abrir() { recalc(); document.getElementById('modal').classList.add('on') }
function cerrar() { document.getElementById('modal').classList.remove('on') }
function filtrar(q) { q = q.toLowerCase(); document.querySelectorAll('[data-prod]').forEach(el => el.style.display = el.dataset.nombre.includes(q) ? '' : 'none') }
pintar(); @if ($errors->any()) abrir(); @endif
</script>
@endsection
