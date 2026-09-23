@php $cfg = ['nombre' => $b->name, 'color' => '#4f3089']; $fmt = fn($n) => '$ ' . number_format((float) $n, 0, ',', '.'); $estados = \App\Models\OrdenTrabajo::ESTADOS; $pasos = ['recibido', 'diagnostico', 'presupuestado', 'aprobado', 'en_curso', 'listo', 'entregado']; $idx = array_search($ot->estado, $pasos, true); @endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $ot->numeroFormateado() }} · {{ $b->name }}</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap">
<style>
  *{box-sizing:border-box} body{margin:0;background:#faf9f7;font-family:Montserrat,system-ui,sans-serif;color:#1c1a18;font-size:14px;line-height:1.5}
  .top{background:linear-gradient(135deg,#4f3089,#a42785);color:#fff;padding:18px 16px} .top h1{margin:0;font-size:20px;font-weight:800} .top p{margin:2px 0 0;font-size:13px;opacity:.9}
  .wrap{max-width:640px;margin:0 auto;padding:16px} .card{background:#fff;border:1px solid #e6e2dc;border-radius:16px;padding:16px;margin-bottom:12px}
  .pasos{display:flex;gap:4px;margin:10px 0 4px} .pasos i{flex:1;height:6px;border-radius:99px;background:#e6e2dc} .pasos i.on{background:#4f3089} .pasos i.now{background:#e4003f}
  .muted{color:#6f6a62;font-size:12px} h3{margin:0 0 6px;font-size:15px} .big{font-size:24px;font-weight:800;color:#4f3089}
  .btn{display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:999px;font-weight:700;border:0;cursor:pointer;font-size:14px;font-family:inherit;width:100%;margin-top:8px} .p{background:#1f9d5b;color:#fff} .s{background:#fff;color:#a10030;border:1px solid #e6e2dc}
  input{width:100%;border:1px solid #e6e2dc;border-radius:10px;padding:10px;font-family:inherit;font-size:14px} label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6f6a62;margin:10px 0 4px}
  table{width:100%;border-collapse:collapse} td{padding:6px 0;border-bottom:1px solid #f2efea} .r{text-align:right;white-space:nowrap}
  .ok{background:#e3f5ea;color:#146c3d;border-radius:12px;padding:12px 14px;font-weight:600}
</style>
</head>
<body>
<div class="top"><h1>{{ $b->name }}</h1><p>Servicio técnico · Orden {{ $ot->numeroFormateado() }}</p></div>
<div class="wrap">
  <div class="card">
    <h3>{{ $ot->equipo }}@if($ot->marca_modelo) · {{ $ot->marca_modelo }}@endif</h3>
    <p class="muted">Ingresó el {{ $ot->fecha_ingreso->format('d/m/Y') }}@if($ot->serie) · serie {{ $ot->serie }}@endif @if($ot->tecnico) · técnico {{ $ot->tecnico->name }}@endif</p>
    <div class="pasos">@foreach($pasos as $k => $p)<i class="{{ $idx !== false && $k < $idx ? 'on' : ($k === $idx ? 'now' : '') }}"></i>@endforeach</div>
    <p><b>Estado: {{ $estados[$ot->estado] }}</b>@if($ot->fecha_prometida && !in_array($ot->estado, ['entregado','cancelado'])) · listo aprox. el {{ $ot->fecha_prometida->format('d/m') }}@endif</p>
    <p class="muted"><b>Falla reportada:</b> {{ $ot->falla }}</p>
    @if($ot->diagnostico)<p class="muted"><b>Diagnóstico:</b> {{ $ot->diagnostico }}</p>@endif
  </div>

  @if(in_array($ot->estado, ['presupuestado', 'diagnostico']) && (float) $ot->presupuesto > 0)
  <div class="card">
    <h3>Presupuesto</h3>
    @if($ot->items->count())<table>@foreach($ot->items as $i)<tr><td>{{ $i->descripcion }} × {{ (float) $i->cantidad }}</td><td class="r">{{ $fmt($i->total) }}</td></tr>@endforeach</table>@endif
    <p class="big">{{ $fmt($ot->presupuesto) }}</p>
    <form method="post" action="/ot/{{ $ot->token }}">@csrf
      <label>Tu nombre (para dejar constancia)</label><input name="nombre" value="{{ $ot->clienteNombre() }}" required>
      <button class="btn p" name="respuesta" value="aprobar">Aprobar presupuesto y reparar</button>
      <button class="btn s" name="respuesta" value="rechazar">No, gracias</button>
    </form>
  </div>
  @elseif($ot->estado === 'aprobado' || $ot->estado === 'en_curso')
  <div class="card ok">Presupuesto aprobado{{ $ot->aprobado_en ? ' el ' . $ot->aprobado_en->format('d/m H:i') : '' }}. Estamos trabajando en tu equipo.</div>
  @elseif($ot->estado === 'listo')
  <div class="card ok">¡Tu equipo está listo para retirar! Total: {{ $fmt($ot->presupuesto) }}.</div>
  @elseif($ot->estado === 'entregado')
  <div class="card ok">Equipo entregado{{ $ot->entregado_en ? ' el ' . $ot->entregado_en->format('d/m/Y') : '' }}. ¡Gracias por confiar en nosotros!</div>
  @elseif($ot->estado === 'cancelado')
  <div class="card"><b>Orden cancelada.</b> Si querés retomarla, escribinos.</div>
  @endif
  <p class="muted" style="text-align:center">{{ $b->name }}@if($b->phone) · {{ $b->phone }}@endif</p>
</div>
</body>
</html>
