@php $color = $cfg['color'] ?? '#e4003f'; @endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $titulo ?? $cfg['nombre'] }}</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap">
<style>
  :root{--c:{{ $color }}}
  *{box-sizing:border-box} body{margin:0;background:#faf9f7;font-family:Montserrat,system-ui,sans-serif;color:#1c1a18;font-size:14px;line-height:1.5}
  .top{background:linear-gradient(135deg,var(--c),#a42785);color:#fff;padding:18px 16px}
  .top .in{max-width:960px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
  .top h1{margin:0;font-size:22px;font-weight:800} .top p{margin:2px 0 0;font-size:13px;opacity:.9}
  .wrap{max-width:960px;margin:0 auto;padding:16px}
  .card{background:#fff;border:1px solid #e6e2dc;border-radius:16px;padding:16px}
  .btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:11px 18px;border-radius:999px;font-weight:700;text-decoration:none;border:0;cursor:pointer;font-size:14px;font-family:inherit}
  .p{background:var(--c);color:#fff} .s{background:#fff;color:#4f3089;border:1px solid #c5bcdd} .g{background:#1f9d5b;color:#fff} .btn:disabled{opacity:.5;cursor:not-allowed}
  .muted{color:#6f6a62;font-size:12px} .ok{background:#e3f5ea;color:#146c3d;border-radius:12px;padding:12px 14px;font-weight:600} .bad{background:#fde8ee;color:#a10030;border-radius:12px;padding:12px 14px;font-weight:600}
  input,select,textarea{width:100%;border:1px solid #e6e2dc;border-radius:10px;padding:10px;font-family:inherit;font-size:14px;background:#fff}
  label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6f6a62;margin:10px 0 4px}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
  .prod{background:#fff;border:1px solid #e6e2dc;border-radius:14px;padding:12px;display:flex;flex-direction:column;gap:6px}
  .prod img{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:10px;background:#f2efea}
  .prod .n{font-weight:700;font-size:14px;line-height:1.25} .prod .pr{font-size:17px;font-weight:800;color:var(--c)}
  .qty{display:flex;align-items:center;gap:6px;margin-top:auto} .qty button{width:34px;height:34px;border-radius:999px;border:1px solid #e6e2dc;background:#fff;font-size:18px;cursor:pointer} .qty span{min-width:28px;text-align:center;font-weight:700}
  .rubro{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#6f6a62;margin:22px 0 8px}
  .carrito{position:fixed;left:0;right:0;bottom:0;background:#fff;border-top:1px solid #e6e2dc;padding:10px 16px;display:none;box-shadow:0 -8px 30px rgba(0,0,0,.08)} .carrito.on{display:block} .carrito .in{max-width:960px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:12px}
  .modal{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:flex-end;justify-content:center;z-index:50;padding:0} .modal.on{display:flex} .modal .box{background:#fff;border-radius:20px 20px 0 0;width:100%;max-width:640px;max-height:92vh;overflow:auto;padding:18px}
  @media(min-width:640px){.modal{align-items:center;padding:16px}.modal .box{border-radius:20px}}
  table{width:100%;border-collapse:collapse} td{padding:8px 0;border-bottom:1px solid #f2efea;vertical-align:top} .r{text-align:right;white-space:nowrap}
  .tag{display:inline-block;font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;background:#f2efea;color:#6f6a62}
  .foot{text-align:center;color:#8a847b;font-size:11px;padding:30px 0 90px}
</style>
</head>
<body>
@yield('cuerpo')
<div class="foot">{{ $b->name }} · Tienda hecha con BigSysWeb</div>
</body>
</html>
