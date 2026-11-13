<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>{{ $titulo }} · BigSys Web</title>
<meta name="theme-color" content="#1e0732">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Jost:wght@300;400;500&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap">
<style>
  *{box-sizing:border-box} body{margin:0;background:#f6f3f8;color:#1b0a16;font-family:"Plus Jakarta Sans",system-ui,sans-serif;font-size:15px;line-height:1.6}
  .cab{color:#fff;padding:48px 20px 40px;background:radial-gradient(90% 70% at 10% 0%,rgba(200,22,106,.55) 0%,rgba(200,22,106,0) 60%),radial-gradient(70% 60% at 100% 100%,rgba(79,48,137,.6) 0%,rgba(79,48,137,0) 60%),linear-gradient(180deg,#2b0a4f 0%,#1e0732 55%,#1b0a16 100%)}
  .cab .in{max-width:760px;margin:0 auto} .logo{font:italic 800 28px Montserrat,"Plus Jakarta Sans",sans-serif;text-decoration:none;color:#fff} .logo small{font:600 13px "Plus Jakarta Sans",sans-serif;color:#c5bcdd;margin-left:3px}
  .kicker{font-size:11px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:#fbe7f0;margin:26px 0 8px} h1{font:300 40px/1.05 Jost,sans-serif;margin:0;letter-spacing:-.01em} .cab p{color:rgba(255,255,255,.75);margin:12px 0 0;max-width:560px}
  main{max-width:760px;margin:-22px auto 60px;padding:0 20px} .card{background:#fff;border:1px solid #e6e0ea;border-radius:24px;padding:28px 30px;box-shadow:0 8px 24px rgba(79,48,137,.06)}
  h2{font:500 22px Jost,sans-serif;margin:28px 0 8px;color:#1b0a16} h2:first-child{margin-top:0} ul{padding-left:20px} li{margin:4px 0} a{color:#a42785} .muted{color:#6f6570;font-size:13px}
  nav{display:flex;gap:14px;flex-wrap:wrap;margin:18px 0 0;font-size:13px} nav a{color:#fff;opacity:.8;text-decoration:none;border:1px solid rgba(255,255,255,.25);border-radius:999px;padding:6px 12px} nav a.on{background:#fff;color:#1e0732;opacity:1}
</style>
</head>
<body>
<header class="cab"><div class="in">
  <a class="logo" href="/">BigSys<small>web</small></a>
  <p class="kicker">{{ $kicker }}</p>
  <h1>{{ $titulo }}</h1>
  <p>{{ $bajada }}</p>
  <nav><a href="/privacidad" class="{{ $seccion === 'privacidad' ? 'on' : '' }}">Privacidad</a><a href="/terminos" class="{{ $seccion === 'terminos' ? 'on' : '' }}">Términos de uso</a><a href="/privacidad#baja">Borrar mi cuenta</a></nav>
</div></header>
<main><div class="card">
  @yield('contenido')
  <p class="muted" style="margin-top:28px">Última actualización: {{ $actualizado }}. BigSys S.R.L. · Mendoza, Argentina · <a href="mailto:{{ $email }}">{{ $email }}</a></p>
</div></main>
</body>
</html>
