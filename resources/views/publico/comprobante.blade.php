@php $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.'); $cant = fn($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ','); $esPre = $c->tipo === 'PRE'; $respondido = session('respondido'); @endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $c->nombreTipo() }} {{ $c->numeroFormateado() }} · {{ $b->name }}</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap">
<style>
  *{box-sizing:border-box} body{margin:0;background:#faf9f7;font-family:Montserrat,system-ui,sans-serif;color:#1c1a18;font-size:14px;line-height:1.5;padding:20px 12px}
  .wrap{max-width:640px;margin:0 auto;display:grid;gap:14px}
  .cab{background:linear-gradient(135deg,#e4003f,#a42785);color:#fff;border-radius:18px;padding:20px 22px}
  .cab h1{margin:0;font-size:20px;font-weight:800} .cab p{margin:4px 0 0;opacity:.9;font-size:13px}
  .card{background:#fff;border:1px solid #e6e2dc;border-radius:16px;padding:16px 18px}
  table{width:100%;border-collapse:collapse} th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:#6f6a62;padding:6px 0;border-bottom:1px solid #e6e2dc} td{padding:8px 0;border-bottom:1px solid #f2efea;vertical-align:top} .r{text-align:right;white-space:nowrap}
  .tot{display:flex;justify-content:space-between;font-size:18px;font-weight:800;padding-top:10px}
  .btn{display:inline-block;padding:12px 20px;border-radius:999px;font-weight:700;text-decoration:none;border:0;cursor:pointer;font-size:14px;font-family:inherit}
  .p{background:#e4003f;color:#fff} .s{background:#fff;color:#4f3089;border:1px solid #c5bcdd} .v{background:#4f3089;color:#fff} .g{background:#1f9d5b;color:#fff}
  .acc{display:flex;flex-wrap:wrap;gap:10px}
  .ok{background:#e3f5ea;color:#146c3d;border-radius:12px;padding:12px 14px;font-weight:600} .bad{background:#fde8ee;color:#a10030;border-radius:12px;padding:12px 14px;font-weight:600}
  textarea{width:100%;border:1px solid #e6e2dc;border-radius:10px;padding:10px;font-family:inherit;font-size:14px;margin:8px 0}
  .muted{color:#6f6a62;font-size:12px}
</style>
</head>
<body>
<div class="wrap">
  <div class="cab"><h1>{{ $b->name }}</h1><p>{{ $c->nombreTipo() }} {{ $c->numeroFormateado() }} · {{ $c->fecha->format('d/m/Y') }}@if($c->contact) · para {{ $c->contact->name }} @endif </p></div>

  @if($pago === 'ok')<div class="ok">✓ Pago recibido. ¡Gracias! Ya quedó registrado en tu cuenta.</div>
  @elseif($pago === 'error')<div class="bad">El pago no se pudo completar. Podés intentar de nuevo.</div>
  @elseif($pago === 'pendiente')<div class="ok">Tu pago está pendiente de acreditación. Te avisamos cuando se confirme.</div> @endif
  @if($respondido)<div class="ok">✓ Registramos tu respuesta. ¡Gracias!</div> @endif
  @if($c->aprobado_en)<div class="ok">Presupuesto aprobado el {{ $c->aprobado_en->format('d/m/Y') }}.</div> @elseif($c->rechazado_en)<div class="bad">Presupuesto rechazado el {{ $c->rechazado_en->format('d/m/Y') }}.</div> @endif

  <div class="card">
    <table>
      <tr><th>Detalle</th><th class="r">Cant.</th><th class="r">Importe</th></tr>
      @foreach($c->items as $i)<tr><td>{{ $i->descripcion }}@if($i->descuento > 0) <span class="muted">(−{{ $i->descuento }}%)</span> @endif </td><td class="r">{{ $cant($i->cantidad) }} {{ $i->unidad }}</td><td class="r">{{ $fmt($i->total) }}</td></tr> @endforeach
    </table>
    <div class="tot"><span>Total</span><span>{{ $fmt($c->total) }}</span></div>
    @if($c->esFactura() && $c->condicion === 'cta_cte' && $c->fecha_vto)<p class="muted">Vencimiento: {{ $c->fecha_vto->format('d/m/Y') }}@if($c->saldo > 0.005) · Saldo pendiente: <b>{{ $fmt($c->saldo) }}</b> @else · Pagada @endif </p> @endif
    @if($c->notas)<p class="muted">{{ $c->notas }}</p> @endif
  </div>

  <div class="card acc">
    <a class="btn s" href="{{ url('/p/' . $c->public_token . '/pdf') }}" target="_blank">Descargar PDF</a>
    @if($c->esFactura() && $c->saldo > 0.005 && $c->link_pago)<a class="btn p" href="{{ $c->link_pago }}">Pagar {{ $fmt($c->saldo) }}</a> @endif
  </div>

  @if($esPre && ! $c->aprobado_en && ! $c->rechazado_en && $c->estado === 'emitido')
  <div class="card">
    <b>¿Aprobás este presupuesto?</b>
    <form method="post" action="{{ url('/p/' . $c->public_token . '/responder') }}">
      @csrf
      <textarea name="comentario" rows="2" placeholder="Comentario (opcional)"></textarea>
      <div class="acc"><button class="btn g" name="respuesta" value="aprobar">Sí, aprobar</button><button class="btn s" name="respuesta" value="rechazar">No, rechazar</button></div>
    </form>
  </div>
  @endif

  <p class="muted" style="text-align:center">{{ $b->razon_social ?? $b->name }}@if($b->cuit) · CUIT {{ $b->cuit }} @endif @if($b->phone) · {{ $b->phone }} @endif </p>
</div>
</body>
</html>
