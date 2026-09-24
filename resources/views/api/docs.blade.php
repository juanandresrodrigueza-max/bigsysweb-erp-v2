<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>API · BigSysWeb</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap">
<style>
:root{--carmin:#e4003f;--magenta:#a42785;--violeta:#4f3089;--lavanda:#c5bcdd;--fondo:#f7f5fb;--texto:#241c36;--muted:#6f6a82;--borde:#e6e1f0}
*{box-sizing:border-box}body{margin:0;font-family:Montserrat,system-ui,sans-serif;background:var(--fondo);color:var(--texto);font-size:14px}
header{background:linear-gradient(90deg,var(--violeta),var(--magenta));color:#fff;padding:22px 28px}header h1{margin:0;font-size:22px}header p{margin:4px 0 0;opacity:.85}
.wrap{display:grid;grid-template-columns:240px 1fr;gap:24px;max-width:1200px;margin:0 auto;padding:24px 16px}
nav{position:sticky;top:16px;align-self:start;background:#fff;border:1px solid var(--borde);border-radius:14px;padding:12px}nav a{display:block;padding:6px 8px;border-radius:8px;color:var(--texto);text-decoration:none;font-size:13px}nav a:hover{background:var(--fondo)}nav small{color:var(--muted)}
.grupo{background:#fff;border:1px solid var(--borde);border-radius:16px;padding:18px 20px;margin-bottom:16px}.grupo h2{margin:0 0 4px;font-size:17px}.grupo p.desc{margin:0 0 12px;color:var(--muted)}
.ep{display:grid;grid-template-columns:70px 1fr auto;gap:10px;align-items:center;padding:8px 0;border-top:1px solid var(--borde);font-size:13px}.ep code{font-family:ui-monospace,Menlo,monospace}
.m{display:inline-block;text-align:center;font-weight:800;font-size:11px;padding:3px 0;border-radius:6px;color:#fff}.GET{background:#2f855a}.POST{background:var(--violeta)}.PUT,.PATCH{background:#b7791f}.DELETE{background:var(--carmin)}
.tag{font-size:10px;padding:2px 6px;border-radius:999px;background:var(--lavanda);color:var(--violeta);font-weight:700;white-space:nowrap}.tag.pub{background:#e6fffa;color:#2c7a7b}
.guia{background:#fff;border:1px solid var(--borde);border-radius:16px;padding:20px 24px;margin-bottom:16px;line-height:1.55}.guia pre{background:var(--fondo);padding:10px 12px;border-radius:10px;overflow:auto;font-size:12px}.guia h1{font-size:20px;margin-top:0}.guia h2{font-size:16px;margin:18px 0 6px}.guia code{background:var(--fondo);padding:1px 4px;border-radius:4px}
.btn{display:inline-block;background:#fff;color:var(--violeta);border:1px solid #fff;border-radius:999px;padding:6px 12px;font-weight:700;text-decoration:none;font-size:12px;margin-top:10px}
@media(max-width:800px){.wrap{grid-template-columns:1fr}nav{position:static}.ep{grid-template-columns:60px 1fr}.ep .tag{display:none}}
</style>
</head>
<body>
<header><h1>API de BigSysWeb</h1><p>{{ $total }} endpoints · base <code>{{ $base }}</code> · autenticación Bearer</p><a class="btn" href="{{ $base }}/openapi.json">Descargar OpenAPI (JSON)</a></header>
<div class="wrap">
  <nav>
    <a href="#guia"><b>Guía de uso</b></a>
    @foreach($grupos as $g)<a href="#{{ $g['key'] }}">{{ $g['label'] }} <small>({{ count($g['endpoints']) }})</small></a>@endforeach
  </nav>
  <main>
    <div class="guia" id="guia">{!! $guia !!}</div>
    @foreach($grupos as $g)
    <section class="grupo" id="{{ $g['key'] }}">
      <h2>{{ $g['label'] }}</h2><p class="desc">{{ $g['descripcion'] }}</p>
      @foreach($g['endpoints'] as $e)
      <div class="ep"><span class="m {{ $e['metodo'] }}">{{ $e['metodo'] }}</span><span><code>{{ $e['ruta'] }}</code> <span style="color:var(--muted)">· {{ $e['resumen'] }}</span></span><span class="tag {{ $e['publico'] ? 'pub' : '' }}">{{ $e['publico'] ? 'público' : 'Bearer' }}</span></div>
      @endforeach
    </section>
    @endforeach
  </main>
</div>
</body>
</html>
