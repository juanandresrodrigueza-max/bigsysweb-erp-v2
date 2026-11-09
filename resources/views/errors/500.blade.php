<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Algo falló · BigSysWeb</title>
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
@php $orig = isset($exception) && $exception instanceof \Throwable ? ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $exception->getPrevious() ? $exception->getPrevious() : $exception) : null; $hash = $orig ? \App\Services\Monitoreo\ErrorReporter::hashDe($orig) : null; $codigo = $hash ? substr($hash, 0, 8) : null; @endphp
<div class="c">
  <h1>Algo falló de nuestro lado</h1>
  <p>No fue algo que hiciste vos. Ya quedó registrado y el equipo de BigSys lo va a mirar.@if($codigo) Código: <code>{{ $codigo }}</code>.@endif</p>
  <p>Probá volver atrás y repetir lo que estabas haciendo. Si vuelve a pasar, avisanos: nos ayuda saber qué querías hacer.</p>
  @auth
  <form method="POST" action="{{ url('/soporte/error') }}">
    @csrf
    <input type="hidden" name="hash" value="{{ $hash }}">
    <input type="hidden" name="url" value="{{ request()->fullUrl() }}">
    <p><textarea name="mensaje" placeholder="¿Qué estabas haciendo cuando pasó? (opcional)" maxlength="2000"></textarea></p>
    <p><button class="b" type="submit">Avisar a soporte</button> <a class="s" href="{{ url('/') }}">Volver al inicio</a></p>
  </form>
  @else
  <p><a class="b" href="{{ url('/') }}">Volver al inicio</a></p>
  @endauth
  <p><small>BigSysWeb · {{ now()->format('d/m/Y H:i') }}</small></p>
</div>
</body>
</html>
