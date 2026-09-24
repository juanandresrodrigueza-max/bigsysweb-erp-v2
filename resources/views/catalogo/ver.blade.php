@php
  $m = $marca; $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.');
  $cant = fn($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $catalogo->nombre }} · {{ $empresa->name }}</title>
<meta name="robots" content="noindex">
<style>
  :root{--p:{{ $m['color_primario'] }};--tp:{{ $m['texto_primario'] }};--s:{{ $m['color_secundario'] }};--ts:{{ $m['texto_secundario'] }};--suave:{{ $m['suave'] }};--tit:{{ $m['titulo'] }};--ac:{{ $m['acento'] }}}
  *{box-sizing:border-box} body{margin:0;font-family:Montserrat,system-ui,-apple-system,"Segoe UI",sans-serif;color:#1c1a18;background:#f6f5f2;font-size:14px}
  header{background:var(--p);color:var(--tp);padding:22px 16px 18px}
  .wrap{max-width:1080px;margin:0 auto;padding:0 16px}
  header .wrap{display:flex;gap:16px;align-items:center;flex-wrap:wrap;padding:0}
  header img{max-height:56px;max-width:180px;background:#fff;border-radius:10px;padding:6px}
  h1{margin:0;font-size:22px;font-weight:800;text-wrap:balance} .sub{opacity:.9;font-size:13px;margin-top:2px}
  .cliente{margin-left:auto;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.35);border-radius:12px;padding:8px 12px;font-size:12.5px}
  .barra{position:sticky;top:0;z-index:5;background:#fff;border-bottom:1px solid #e6e2dc}
  .barra .wrap{display:flex;gap:10px;align-items:center;padding-block:10px;flex-wrap:wrap}
  .barra input{flex:1;min-width:180px;border:1px solid #d6d1ca;border-radius:999px;padding:9px 14px;font:inherit}
  .chips{display:flex;gap:6px;overflow-x:auto;max-width:100%} .chips a{white-space:nowrap;text-decoration:none;color:var(--tit);border:1px solid #e6e2dc;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:600}
  .btn{background:var(--p);color:var(--tp);border:0;border-radius:999px;padding:9px 16px;font-weight:700;text-decoration:none;font-size:13px;white-space:nowrap}
  .nota{background:#fff;border:1px solid #e6e2dc;border-left:4px solid var(--p);border-radius:10px;padding:10px 14px;margin:16px 0 0;white-space:pre-line}
  h2{font-size:15px;margin:26px 0 10px;color:var(--tit);font-weight:800;scroll-margin-top:70px}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px}
  .card{background:#fff;border:1px solid #e6e2dc;border-radius:14px;overflow:hidden;display:flex;flex-direction:column}
  .foto{aspect-ratio:4/3;background:var(--suave);display:grid;place-items:center;overflow:hidden} .foto img{width:100%;height:100%;object-fit:cover} .foto span{font-size:30px;font-weight:800;color:var(--ac);opacity:.35}
  .cuerpo{padding:10px 12px 12px;display:flex;flex-direction:column;gap:4px;flex:1}
  .nom{font-weight:700;line-height:1.3} .cod{font-size:11px;color:#6f6a62} .desc{font-size:12px;color:#4a4640}
  .precio{margin-top:auto;font-size:18px;font-weight:800;color:var(--ac);font-variant-numeric:tabular-nums}
  .precio small{font-size:11px;color:#6f6a62;font-weight:600}
  .tag{display:inline-block;font-size:10.5px;font-weight:700;border-radius:999px;padding:2px 8px;background:var(--s);color:var(--ts);width:fit-content}
  .sin{color:#b0002f;font-size:11.5px;font-weight:700}
  footer{color:#6f6a62;font-size:12px;text-align:center;padding:30px 16px 40px;white-space:pre-line}
  @media (max-width:520px){.grid{grid-template-columns:1fr 1fr;gap:8px} .precio{font-size:15px} h1{font-size:18px} .cliente{margin-left:0}}
</style>
</head>
<body>
<header><div class="wrap">
  @if($m['logo_uri'])<img src="{{ $m['logo_uri'] }}" alt="{{ $empresa->name }}">@endif
  <div><h1>{{ $catalogo->nombre }}</h1><div class="sub">{{ $empresa->razon_social ?: $empresa->name }} · {{ $aclaracion }} · Actualizado {{ now()->format('d/m/Y') }}</div></div>
  @if($cliente)<div class="cliente">Precios para <b>{{ $cliente->name }}</b>@if($especiales) · {{ $especiales }} con condición especial @endif</div>@endif
</div></header>
<div class="barra"><div class="wrap">
  <input id="q" type="search" placeholder="Buscar en {{ $total }} artículos…" autocomplete="off">
  <a class="btn" href="{{ url()->current() }}/pdf{{ $cliente ? '?c=' . request('c') : '' }}">Bajar PDF</a>
  @if(count($rubros) > 1)<nav class="chips">@foreach($rubros as $i => $r)<a href="#r{{ $i }}">{{ $r['nombre'] }}</a>@endforeach</nav>@endif
</div></div>
<main class="wrap">
  @if($catalogo->nota)<div class="nota">{{ $catalogo->nota }}</div>@endif
  @foreach($rubros as $i => $r)
  <section data-rubro><h2 id="r{{ $i }}">{{ $r['nombre'] }}</h2>
    <div class="grid">
      @foreach($r['items'] as $p)
      <article class="card" data-buscar="{{ mb_strtolower($p['nombre'] . ' ' . $p['sku'] . ' ' . $p['barcode'] . ' ' . $r['nombre']) }}">
        @if($catalogo->mostrar_fotos)<div class="foto">@if($p['imagen'])<img src="{{ $p['imagen'] }}" alt="" loading="lazy">@else<span>{{ mb_strtoupper(mb_substr($p['nombre'], 0, 1)) }}</span>@endif</div>@endif
        <div class="cuerpo">
          <div class="nom">{{ $p['nombre'] }}</div>
          @if($catalogo->mostrar_codigo && $p['sku'])<div class="cod">Cód. {{ $p['sku'] }}</div>@endif
          @if($p['descripcion'])<div class="desc">{{ \Illuminate\Support\Str::limit($p['descripcion'], 110) }}</div>@endif
          @if($p['especial'])<span class="tag">Precio especial{{ $p['descuento'] > 0 ? ' · ' . $cant($p['descuento']) . '% off' : '' }}</span>@endif
          @if($p['desc_cant'])<span class="cod">{{ $cant($p['desc_cant']['pct']) }}% menos llevando {{ $cant($p['desc_cant']['min']) }} o más</span>@endif
          @if($p['stock'] !== null)<span class="cod">Stock: {{ $cant($p['stock']) }} {{ $p['unidad'] }}</span>@endif
          @if($p['sin_stock'])<span class="sin">Sin stock por ahora</span>@endif
          <div class="precio">{{ $fmt($p['precio']) }} <small>/ {{ $p['unidad'] ?: 'un' }}</small></div>
        </div>
      </article>
      @endforeach
    </div>
  </section>
  @endforeach
  @if(! $total)<p class="nota">Este catálogo no tiene artículos para mostrar.</p>@endif
</main>
<footer>{{ $empresa->name }}@if($empresa->phone) · {{ $empresa->phone }}@endif @if($empresa->email) · {{ $empresa->email }}@endif
@foreach($m['lineas_extra'] as $l){{ "\n" . $l }}@endforeach
Precios sujetos a cambio sin previo aviso.@if(trim((string) $m['pie']) !== ''){{ "\n" . $m['pie'] }}@endif</footer>
<script>
  const q = document.getElementById('q')
  q.addEventListener('input', () => {
    const t = q.value.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
    document.querySelectorAll('[data-buscar]').forEach(el => { el.hidden = t && !el.dataset.buscar.normalize('NFD').replace(/[̀-ͯ]/g, '').includes(t) })
    document.querySelectorAll('[data-rubro]').forEach(s => { s.hidden = !s.querySelector('[data-buscar]:not([hidden])') })
  })
</script>
</body>
</html>
