<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manual de usuario · BigSysWeb</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap">
<style>
:root{--carmin:#e4003f;--magenta:#a42785;--violeta:#4f3089;--lavanda:#c5bcdd;--fondo:#f7f5fb;--texto:#241c36;--muted:#6f6a82;--borde:#e6e1f0}
*{box-sizing:border-box}body{margin:0;font-family:Montserrat,system-ui,sans-serif;background:var(--fondo);color:var(--texto);font-size:14px;line-height:1.6}
header{background:linear-gradient(90deg,var(--violeta),var(--magenta));color:#fff;padding:28px 24px}header h1{margin:0;font-size:26px}header p{margin:6px 0 0;opacity:.9;max-width:720px}
.wrap{display:grid;grid-template-columns:260px 1fr;gap:28px;max-width:1180px;margin:0 auto;padding:24px 16px}
nav{position:sticky;top:16px;align-self:start;background:#fff;border:1px solid var(--borde);border-radius:14px;padding:12px;max-height:calc(100vh - 32px);overflow:auto}nav a{display:block;padding:5px 8px;border-radius:8px;color:var(--texto);text-decoration:none;font-size:13px}nav a:hover{background:var(--fondo)}nav .num{color:var(--muted);font-weight:700;margin-right:4px}nav summary{cursor:pointer;list-style:none}nav summary::-webkit-details-marker{display:none}nav a.sub{padding-left:22px;font-size:12px;color:var(--muted)}nav a.sub:hover{color:var(--texto)}mark{background:#ffe9a8;padding:0 2px;border-radius:3px}
article{background:#fff;border:1px solid var(--borde);border-radius:16px;padding:24px 28px;margin-bottom:20px;page-break-after:always}article h1{font-size:22px;margin:0 0 4px;color:var(--violeta)}article .res{color:var(--muted);margin:0 0 14px}article h2{font-size:16px;margin:20px 0 6px;border-bottom:1px solid var(--borde);padding-bottom:4px}article h3{font-size:14px;margin:14px 0 4px}article p{margin:.4rem 0;max-width:70ch}article ul,article ol{padding-left:1.3rem;max-width:70ch}article li{margin:.2rem 0}article code{background:var(--fondo);padding:1px 5px;border-radius:4px;font-size:.9em}article pre{background:var(--fondo);padding:10px 12px;border-radius:10px;overflow:auto;font-size:12px}article strong{font-weight:700}
.tools{display:flex;gap:8px;margin-top:12px}.btn{display:inline-block;background:#fff;color:var(--violeta);border-radius:999px;padding:6px 14px;font-weight:700;text-decoration:none;font-size:12px;border:1px solid #fff;cursor:pointer}
@media(max-width:800px){.wrap{grid-template-columns:1fr}nav{position:static;max-height:none}}
@media print{header{background:var(--violeta)!important;-webkit-print-color-adjust:exact}nav,.tools{display:none}.wrap{display:block;padding:0}article{border:0;box-shadow:none;padding:0 0 18px}}
</style>
</head>
<body>
<header>
  <h1>Manual de usuario · BigSysWeb</h1>
  <p>Todo lo que hace el sistema, explicado para usarlo sin ayuda: desde la puesta en marcha hasta cada módulo. Generado el {{ now()->format('d/m/Y') }} desde las guías del centro de ayuda.</p>
  <div class="tools"><button class="btn" onclick="window.print()">Imprimir / guardar PDF</button><a class="btn" href="/ayuda">Volver al centro de ayuda</a></div>
</header>
<div class="wrap">
  <nav>
    <input id="filtro" placeholder="Buscar en el manual…" style="width:100%;padding:7px 10px;border:1px solid var(--borde);border-radius:8px;font:inherit;margin-bottom:8px">
    @foreach($articulos as $i => $a)<details class="idx" @if($i === 0) open @endif><summary><a href="#{{ $a['slug'] }}"><span class="num">{{ $i + 1 }}.</span>{{ $a['titulo'] }}</a></summary>@foreach($a['secciones'] as $s)<a class="sub" href="#{{ $s['ancla'] }}">{{ $s['titulo'] }}</a>@endforeach</details>@endforeach
  </nav>
  <main>
    @foreach($articulos as $i => $a)
    <article id="{{ $a['slug'] }}">
      <h1>{{ $i + 1 }}. {{ $a['titulo'] }}</h1>
      @if($a['resumen'])<p class="res">{{ $a['resumen'] }}</p>@endif
      {!! $a['html'] !!}
    </article>
    @endforeach
  </main>
</div>
<script>
// Filtro del manual: resalta la palabra en todo el texto y deja solo las secciones que la contienen.
const filtro = document.getElementById('filtro'); const arts = [...document.querySelectorAll('article')]
function norm(t) { return t.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '') }
function limpiar() { document.querySelectorAll('mark').forEach(m => m.replaceWith(m.textContent)); arts.forEach(a => { a.style.display = ''; a.querySelectorAll('h2,h3,p,ul,ol,pre').forEach(e => e.style.display = '') }) }
filtro?.addEventListener('input', () => {
  limpiar(); const q = norm(filtro.value.trim()); if (q.length < 2) return
  const raiz = q.length >= 5 ? q.slice(0, -2) : q
  arts.forEach(a => {
    let alguna = false; let visible = false
    a.querySelectorAll('h2,h3,p,ul,ol,pre').forEach(e => { if (e.tagName === 'H2') visible = norm(e.textContent).includes(raiz); const hit = norm(e.textContent).includes(raiz); if (hit) { alguna = true; visible = true } if (!visible && !hit && e.tagName !== 'H2') e.style.display = 'none'; if (e.tagName === 'H2' && !visible) { e.style.display = 'none' } })
    // segunda pasada: mostrar el h2 de cada bloque con coincidencias
    let h2 = null; a.querySelectorAll('h2,h3,p,ul,ol,pre').forEach(e => { if (e.tagName === 'H2') h2 = e; else if (e.style.display !== 'none' && h2) h2.style.display = '' })
    a.style.display = alguna ? '' : 'none'
    if (alguna) a.querySelectorAll('p,li,h2,h3').forEach(e => { if (e.style.display === 'none') return; const re = new RegExp('(' + raiz.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '[a-záéíóúñ]*)', 'gi'); e.innerHTML = e.innerHTML.replace(/>([^<]+)</g, (m, t) => '>' + t.replace(re, '<mark>$1</mark>') + '<') })
  })
})
</script>
</body>
</html>
