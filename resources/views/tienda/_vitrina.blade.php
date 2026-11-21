{{-- Fase 127: la carta vitrina. Arriba el local (foto, historia, horario); después cada rubro es un carrusel con un plato por
     pantalla: foto grande (redonda y girando si es el plato visto desde arriba), ingredientes, alérgenos, opciones y precio.
     El pedido usa las mismas funciones que la carta en lista (sumar, cambiar, opciones), que están más abajo en menu.blade. --}}
@php
  $portada = \App\Services\Gastronomia\CartaVitrinaService::urlPortada($b);
  $titulo = $cfg['menu_historia_titulo'] ?: $cfg['nombre'];
  $alerg = fn($k) => \App\Services\Gastronomia\CartaVitrinaService::alergeno($k, $lang);
@endphp
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,600;1,9..144,500&display=swap">
<style>
  body{background:#17110d;color:#f3eadf}
  .foot{color:#9c8f80}
  .card,.modal .box,.carrito,#sug{color:#1c1a18}
  .vt{--fondo:#17110d;--tinta:#f3eadf;--suave:#b9ab99;--linea:#3a2e25;--brasa:#2a1f18;font-family:Montserrat,system-ui,sans-serif}
  .vt a{color:inherit}
  .vt-hero{position:relative;min-height:62vh;display:flex;align-items:flex-end;background:#120d0a center/cover no-repeat}
  .vt-hero::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(23,17,13,.15) 0%,rgba(23,17,13,.55) 45%,#17110d 100%)}
  .vt-hero.sin-foto{min-height:auto;padding-top:28px;background:radial-gradient(120% 90% at 85% 0%,color-mix(in srgb,var(--c) 38%,transparent),transparent 60%),#17110d}
  .vt-hero-in{position:relative;z-index:1;max-width:960px;width:100%;margin:0 auto;padding:28px 18px 26px}
  .vt-kicker{font-size:11px;font-weight:800;letter-spacing:.22em;text-transform:uppercase;color:var(--c);margin:0 0 8px}
  .vt-hero h1{font-family:Fraunces,Georgia,serif;font-weight:600;font-size:clamp(30px,8vw,54px);line-height:1.02;margin:0 0 12px;text-wrap:balance;max-width:16ch}
  .vt-hist{color:#e4d8c8;max-width:58ch;margin:0 0 12px;font-size:14.5px;line-height:1.6;white-space:pre-line}
  .vt-hor{font-size:12px;color:var(--suave);margin:0 0 16px;letter-spacing:.02em}
  .vt-acc{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
  .vt .btn.s{background:transparent;color:var(--tinta);border:1px solid #5a4a3d}
  .vt-idiomas a{font-size:11px;font-weight:700;padding:5px 9px;border:1px solid #5a4a3d;border-radius:999px;text-decoration:none;color:var(--suave)} .vt-idiomas a.on{color:var(--tinta);border-color:var(--tinta)}
  .vt-nav{position:sticky;top:env(safe-area-inset-top,0px);z-index:20;background:rgba(23,17,13,.92);backdrop-filter:blur(8px);border-bottom:1px solid var(--linea)}
  .vt-nav .in{max-width:960px;margin:0 auto;display:flex;gap:6px;overflow-x:auto;padding:10px 16px;scrollbar-width:none} .vt-nav .in::-webkit-scrollbar{display:none}
  .vt-nav a{white-space:nowrap;font-size:12px;font-weight:700;padding:7px 13px;border-radius:999px;background:var(--brasa);text-decoration:none;color:var(--suave)}
  .vt-rubro{max-width:960px;margin:0 auto;padding:26px 0 8px;scroll-margin-top:56px}
  .vt-rubro h2{display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin:0 16px 12px;font-family:Fraunces,Georgia,serif;font-weight:600;font-size:24px}
  .vt-rubro h2 span{font-family:Montserrat,sans-serif;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--suave)}
  .vt-car{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;gap:0;-webkit-overflow-scrolling:touch} .vt-car::-webkit-scrollbar{display:none}
  .vt-plato{flex:0 0 100%;scroll-snap-align:center;padding:0 16px;display:grid;gap:16px;align-content:start}
  @media(min-width:760px){.vt-plato{grid-template-columns:minmax(0,1fr) minmax(0,1fr);align-items:center;gap:28px}}
  .vt-foto{position:relative;aspect-ratio:1/1;max-width:100%;border-radius:22px;overflow:hidden;background:radial-gradient(70% 70% at 50% 40%,#3a2b20,#1d1611)}
  .vt-foto img{width:100%;height:100%;object-fit:cover;display:block}
  .vt-foto.gira{background:radial-gradient(60% 60% at 50% 50%,#3a2b20 0%,#22190f 70%,transparent 71%),transparent;overflow:visible}
  .vt-foto.gira img{border-radius:50%;width:88%;height:88%;margin:6%;box-shadow:0 26px 50px rgba(0,0,0,.55);animation:vt-girar 70s linear infinite}
  @keyframes vt-girar{to{transform:rotate(360deg)}}
  @media (prefers-reduced-motion: reduce){.vt-foto.gira img{animation:none}}
  .vt-foto.sin{aspect-ratio:16/7} @media(min-width:760px){.vt-foto.sin{aspect-ratio:1/1}}
  .vt-foto.sin .ini{font-size:64px}
  .vt-foto .ini{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-family:Fraunces,Georgia,serif;font-size:96px;color:color-mix(in srgb,var(--c) 70%,#f3eadf);opacity:.55}
  .vt-num{font-size:11px;font-weight:800;letter-spacing:.2em;color:var(--suave);margin:0 0 4px;font-variant-numeric:tabular-nums}
  .vt-plato h3{font-family:Fraunces,Georgia,serif;font-weight:600;font-size:clamp(26px,6.5vw,38px);line-height:1.08;margin:0 0 8px;text-wrap:balance}
  .vt-desc{color:#ddd0bf;margin:0 0 12px;line-height:1.55;max-width:52ch}
  .vt-lbl{font-size:10px;font-weight:800;letter-spacing:.18em;text-transform:uppercase;color:var(--suave);margin:12px 0 6px}
  .vt-ing{display:flex;flex-wrap:wrap;gap:6px;margin:0;padding:0;list-style:none} .vt-ing li{font-size:12px;padding:4px 10px;border-radius:999px;background:var(--brasa);border:1px solid var(--linea)}
  .vt-al{display:flex;flex-wrap:wrap;gap:6px} .vt-al span{font-size:12px;font-weight:700;padding:4px 10px;border-radius:999px;background:#3b2a12;color:#f4c46b;border:1px solid #6b4b1c}
  .vt-op{font-size:12.5px;color:#ddd0bf;margin:10px 0 0} .vt-op b{color:var(--tinta)}
  .vt-pie{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:16px;padding-top:14px;border-top:1px solid var(--linea)}
  .vt-precio{font-size:26px;font-weight:800;font-variant-numeric:tabular-nums;color:var(--tinta)} .vt-precio s{display:block;font-size:12px;font-weight:500;color:var(--suave)} .vt-precio small{display:block;font-size:10px;font-weight:700;color:var(--c);letter-spacing:.08em;text-transform:uppercase}
  .vt .qty button{background:var(--brasa);border-color:#5a4a3d;color:var(--tinta)} .vt .qty span{color:var(--tinta)}
  .vt-agot{font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;padding:8px 12px;border-radius:999px;background:#3a1a1d;color:#ff9aa8}
  .vt-pasar{display:flex;align-items:center;justify-content:center;gap:14px;padding:12px 0 4px} .vt-pasar button{width:34px;height:34px;border-radius:999px;border:1px solid #5a4a3d;background:var(--brasa);color:var(--tinta);font-size:20px;line-height:1;cursor:pointer;font-family:inherit}
  .vt-puntos{display:flex;justify-content:center;gap:6px} .vt-puntos i{width:6px;height:6px;border-radius:999px;background:#4a3c31;transition:all .2s} .vt-puntos i.on{width:18px;background:var(--c)}
  .vt-mesa{font-size:13px;color:#e4d8c8;margin:0 0 14px} .vt-mesa b{color:var(--tinta)}
</style>
<div class="vt">
  <header class="vt-hero {{ $portada ? '' : 'sin-foto' }}" @if ($portada) style="background-image:url('{{ $portada }}')" @endif data-vitrina>
    <div class="vt-hero-in">
      <p class="vt-kicker">{{ $cfg['nombre'] }}</p>
      <h1>{{ $titulo }}</h1>
      @if ($cfg['menu_historia'])<p class="vt-hist">{{ $cfg['menu_historia'] }}</p>@endif
      @if ($cfg['menu_horario'])<p class="vt-hor">{{ $cfg['menu_horario'] }}</p>@endif
      <p class="vt-mesa">@if ($mesa)<b>{{ $mesa->nombre }}</b> · {{ $t['en_mesa'] }}.@else {{ $t['sin_mesa'] }}.@endif</p>
      <div class="vt-acc">
        <a href="#carta" class="btn p">{{ $t['ver_carta'] }}</a>
        @if ($cfg['reservas_activas'])<a class="btn s" href="/r/{{ $cfg['slug'] }}">{{ $t['reservar'] }}</a>@endif
        @if (! empty($idiomas))<span class="vt-idiomas" style="display:flex;gap:4px;margin-left:auto">@foreach (['es' => 'ES'] + array_map(fn($x) => strtoupper($x), array_combine(array_keys($idiomas), array_keys($idiomas))) as $k => $lbl)<a href="?{{ http_build_query(array_filter(['mesa' => $mesaRef, 'lang' => $k === 'es' ? null : $k])) }}" class="{{ $lang === $k ? 'on' : '' }}">{{ $lbl }}</a>@endforeach</span>@endif
      </div>
    </div>
  </header>

  @if (count($cat['rubros']) > 1)
  <nav class="vt-nav" id="carta"><div class="in">@foreach ($cat['rubros'] as $i => $r)<a href="#r{{ $i }}">{{ $r['nombre'] }}</a>@endforeach</div></nav>
  @else <div id="carta"></div> @endif

  @forelse ($cat['rubros'] as $i => $r)
    <section class="vt-rubro" id="r{{ $i }}">
      <h2>{{ $r['nombre'] }} @if (count($r['items']) > 1)<span>{{ count($r['items']) }} {{ $t['platos'] }}</span>@endif</h2>
      <div class="vt-car" data-car>
        @foreach ($r['items'] as $k => $p)
          @php $ing = collect(preg_split('/\s*[,;\n]\s*/', (string) ($p['ingredientes'] ?? '')))->filter()->take(12); @endphp
          <article class="vt-plato" data-plato="{{ $p['id'] }}">
            <div class="vt-foto {{ ! empty($p['foto_gira']) && $p['imagen'] ? 'gira' : '' }} {{ $p['imagen'] ? '' : 'sin' }}">
              @if ($p['imagen'])<img src="{{ $p['imagen'] }}" alt="{{ $p['nombre'] }}" loading="{{ $k === 0 && $i === 0 ? 'eager' : 'lazy' }}">@else<span class="ini" aria-hidden="true">{{ mb_strtoupper(mb_substr($p['nombre'], 0, 1)) }}</span>@endif
            </div>
            <div>
              <p class="vt-num">{{ str_pad($k + 1, 2, '0', STR_PAD_LEFT) }} / {{ str_pad(count($r['items']), 2, '0', STR_PAD_LEFT) }}</p>
              <h3>{{ $p['nombre'] }}</h3>
              @if ($p['descripcion'])<p class="vt-desc">{{ $p['descripcion'] }}</p>@endif
              @if ($ing->isNotEmpty())<p class="vt-lbl">{{ $t['ingredientes'] }}</p><ul class="vt-ing">@foreach ($ing as $x)<li>{{ $x }}</li>@endforeach</ul>@endif
              @if (! empty($p['alergenos']))<p class="vt-lbl">{{ $t['contiene'] }}</p><div class="vt-al" data-alergenos>@foreach ($p['alergenos'] as $a)<span>{{ $alerg($a) }}</span>@endforeach</div>@endif
              @foreach (array_slice($p['modificadores'] ?? [], 0, 2) as $g)
                <p class="vt-op"><b>{{ $g['nombre'] }}:</b> {{ collect($g['opciones'] ?? [])->map(fn($o) => $o['nombre'] . ((float) ($o['precio'] ?? 0) > 0 ? ' (+' . $fmt($o['precio']) . ')' : ''))->implode(' · ') }}</p>
              @endforeach
              <div class="vt-pie">
                <div class="vt-precio">@if (! empty($p['hh']))<s>{{ $fmt($p['precio_antes']) }}</s>@endif{{ $fmt($p['precio']) }}@if (! empty($p['hh']))<small>{{ $p['hh'] }}</small>@endif</div>
                @if (! empty($p['agotado']))<span class="vt-agot" data-agotado="{{ $p['id'] }}">{{ $t['agotado'] }}</span>
                @elseif ($mesa)<div class="qty" style="margin:0"><button type="button" onclick="cambiar({{ $p['id'] }}, -1)" aria-label="Uno menos">−</button><span id="q{{ $p['id'] }}">0</span><button type="button" onclick="sumar({{ $p['id'] }})" aria-label="{{ $t['agregar'] }}">+</button></div>@endif
              </div>
            </div>
          </article>
        @endforeach
      </div>
      @if (count($r['items']) > 1)<div class="vt-pasar"><button type="button" data-ir="-1" aria-label="Plato anterior">‹</button><div class="vt-puntos" data-puntos>@foreach ($r['items'] as $k => $p)<i class="{{ $k === 0 ? 'on' : '' }}"></i>@endforeach</div><button type="button" data-ir="1" aria-label="Plato siguiente">›</button></div>@endif
    </section>
  @empty
    <div class="wrap"><div class="card" style="color:#1c1a18">La carta todavía no está cargada.</div></div>
  @endforelse
</div>
<script>
// Los puntitos de cada carrusel siguen al plato que se está viendo.
// Las flechas pasan de plato en la compu (en el celular se pasa con el dedo).
document.querySelectorAll('[data-car]').forEach(car => {
  const sec = car.parentElement, puntos = sec.querySelectorAll('[data-puntos] i'); if (!puntos.length) return
  car.addEventListener('scroll', () => { const i = Math.round(car.scrollLeft / car.clientWidth); puntos.forEach((p, k) => p.classList.toggle('on', k === i)) }, { passive: true })
  sec.querySelectorAll('[data-ir]').forEach(b => b.addEventListener('click', () => car.scrollBy({ left: Number(b.dataset.ir) * car.clientWidth, behavior: 'smooth' })))
})
</script>
