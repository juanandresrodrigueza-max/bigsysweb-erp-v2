<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sin permiso · BigSysWeb</title>
<style>
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f6f5f2;color:#1f1d1a;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px}
.c{background:#fff;border-radius:16px;box-shadow:0 2px 12px rgba(0,0,0,.06);max-width:520px;width:100%;padding:28px}
h1{font-size:22px;margin:0 0 8px}p{line-height:1.5;margin:8px 0}code{background:#f0eee9;padding:2px 6px;border-radius:6px}
.b{display:inline-block;background:#b3263c;color:#fff;border:0;border-radius:999px;padding:10px 18px;font-weight:700;cursor:pointer;text-decoration:none}
.s{display:inline-block;color:#5a5650;padding:10px 12px;text-decoration:none}textarea{width:100%;box-sizing:border-box;border:1px solid #ddd;border-radius:10px;padding:10px;font:inherit;min-height:70px}
small{color:#7a766f}
</style>
</head>
<body>
<div class="c">
  <h1>No tenés permiso para esto</h1>
  <p>{{ ($exception?->getMessage() ?: null) ?? "Tu usuario no tiene acceso a esta pantalla o a esta acción." }}</p>
  <p>Los permisos los maneja el dueño de la empresa en Configuración → Usuarios.</p>
  <p><a class="b" href="{{ url('/') }}">Volver al inicio</a> <a class="s" href="javascript:history.back()">Volver atrás</a></p>
  <p><small>BigSysWeb · {{ now()->format('d/m/Y H:i') }}</small></p>
</div>
</body>
</html>
