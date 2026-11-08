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
          <div style="font-weight:800;color:var(--c);white-space:nowrap;text-align:right">@if (! empty($p['hh']))<div style="font-size:11px;color:#888;text-decoration:line-through;font-weight:400">{{ $fmt($p['precio_antes']) }}</div>@endif{{ $fmt($p['precio']) }}@if (! empty($p['hh']))<div style="font-size:10px;font-weight:700">{{ $p['hh'] }}</div>@endif</div>
          @if ($mesa) <div class="qty" style="margin:0"><button type="button" onclick="cambiar({{ $p['id'] }}, -1)">−</button><span id="q{{ $p['id'] }}">0</span><button type="button" onclick="sumar({{ $p['id'] }})">+</button></div> @endif
        </div>
      @endforeach
    </div>
  @empty
    <div class="card">La carta todavía no está cargada.</div>
  @endforelse
</div>
@if ($mesa)
<div class="wrap" style="display:flex;gap:8px;padding-top:0">
  @if (session('llamado'))<div class="card" style="flex:1;text-align:center;font-weight:700">{{ session('llamado') === 'cuenta' ? 'Listo, ya te llevan la cuenta.' : 'Listo, el mozo ya viene.' }}</div>
  @else
  <form method="post" action="/m/{{ $cfg['slug'] }}/llamar" style="flex:1">@csrf<input type="hidden" name="mesa_id" value="{{ $mesa->id }}"><input type="hidden" name="motivo" value="mozo"><button class="btn s" style="width:100%">🙋 Llamar al mozo</button></form>
  <form method="post" action="/m/{{ $cfg['slug'] }}/llamar" style="flex:1">@csrf<input type="hidden" name="mesa_id" value="{{ $mesa->id }}"><input type="hidden" name="motivo" value="cuenta"><button class="btn s" style="width:100%">🧾 Pedir la cuenta</button></form>
  @endif
</div>
<div class="modal" id="opc"><div class="box"><h2 id="opcT" style="margin:0 0 8px"></h2><div id="opcG"></div><p id="opcE" style="color:#c00;font-size:13px"></p>
  <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px"><button type="button" class="btn s" onclick="document.getElementById('opc').classList.remove('on')">Volver</button><button type="button" class="btn p" onclick="agregarOpc()">Agregar</button></div></div></div>
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
const P = {!! json_encode(collect($cat['rubros'])->flatMap(fn($r) => $r['items'])->mapWithKeys(fn($p) => [$p['id'] => ['n' => $p['nombre'], 'p' => $p['precio'], 'm' => $p['modificadores'] ?? []]])) !!};
const fmt = n => '$ ' + Number(n).toLocaleString('es-AR', { maximumFractionDigits: 0 });
// Cada línea del pedido: plato + opciones elegidas + cantidad. Un plato con opciones distintas son dos líneas.
let lineas = [], opcId = null, opcSel = {};
const extra = l => (P[l.id].m || []).reduce((a, g) => a + (l.mods[g.nombre] || []).reduce((b, n) => b + (Number((g.opciones.find(o => o.nombre === n) || {}).precio) || 0), 0), 0);
const desc = l => P[l.id].n + (Object.values(l.mods).flat().length ? ' (' + Object.values(l.mods).flat().join(', ') + ')' : '');
function sumar(id) { if ((P[id].m || []).length) return abrirOpc(id); const l = lineas.find(x => x.id == id && !Object.keys(x.mods).length); l ? l.q++ : lineas.push({ id, mods: {}, q: 1 }); pintar() }
function cambiar(id, d) { const ls = lineas.filter(x => x.id == id); const l = ls[ls.length - 1]; if (!l) return; l.q += d; if (l.q <= 0) lineas.splice(lineas.indexOf(l), 1); pintar() }
function abrirOpc(id) { opcId = id; opcSel = {}; document.getElementById('opcT').textContent = P[id].n; document.getElementById('opcE').textContent = '';
  document.getElementById('opcG').innerHTML = P[id].m.map((g, gi) => `<label>${g.nombre}${g.obligatorio ? ' *' : ''}</label><div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px">` + g.opciones.map((o, oi) => `<button type="button" class="btn s" id="o${gi}_${oi}" onclick="tocar(${gi},${oi})">${o.nombre}${Number(o.precio) ? ' +' + fmt(o.precio) : ''}</button>`).join('') + '</div>').join('');
  document.getElementById('opc').classList.add('on') }
function tocar(gi, oi) { const g = P[opcId].m[gi], n = g.opciones[oi].nombre, a = opcSel[g.nombre] || []; opcSel[g.nombre] = a.includes(n) ? a.filter(x => x !== n) : (g.multiple ? [...a, n] : [n]);
  g.opciones.forEach((o, k) => { const el = document.getElementById(`o${gi}_${k}`); const on = (opcSel[g.nombre] || []).includes(o.nombre); el.style.background = on ? 'var(--c)' : ''; el.style.color = on ? '#fff' : '' }) }
function agregarOpc() { const falta = P[opcId].m.find(g => g.obligatorio && !(opcSel[g.nombre] || []).length); if (falta) { document.getElementById('opcE').textContent = 'Elegí ' + falta.nombre + '.'; return }
  lineas.push({ id: opcId, mods: JSON.parse(JSON.stringify(opcSel)), q: 1 }); document.getElementById('opc').classList.remove('on'); pintar() }
function pintar() { let t = 0, n = 0; Object.keys(P).forEach(id => { const el = document.getElementById('q' + id); if (el) el.textContent = lineas.filter(l => l.id == id).reduce((a, l) => a + l.q, 0) });
  lineas.forEach(l => { t += (P[l.id].p + extra(l)) * l.q; n += l.q }); document.getElementById('cTotal').textContent = fmt(t); document.getElementById('cItems').textContent = n ? `· ${n} ítem${n > 1 ? 's' : ''}` : ''; document.getElementById('carrito').classList.toggle('on', n > 0) }
function abrir() { document.getElementById('resumen').innerHTML = lineas.map(l => `<tr><td>${desc(l)} <span class="muted">× ${l.q}</span></td><td class="r">${fmt((P[l.id].p + extra(l)) * l.q)}</td></tr>`).join('');
  document.getElementById('hidden').innerHTML = lineas.map((l, i) => `<input type="hidden" name="items[${i}][product_id]" value="${l.id}"><input type="hidden" name="items[${i}][cantidad]" value="${l.q}">` + Object.entries(l.mods).flatMap(([g, ns]) => ns.map((n, k) => `<input type="hidden" name="items[${i}][modificadores][${g}][${k}]" value="${n.replace(/"/g, '&quot;')}">`)).join('')).join('');
  document.getElementById('modal').classList.add('on') }
function cerrar() { document.getElementById('modal').classList.remove('on') }
</script>
@endif
@endsection
