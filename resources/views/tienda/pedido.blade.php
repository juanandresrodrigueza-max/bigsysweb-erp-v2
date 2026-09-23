@extends('tienda._layout', ['titulo' => 'Pedido ' . $p->numeroFormateado()])
@section('cuerpo')
@php $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.'); $est = ['nuevo' => ['Recibido', 'Ya lo vimos, en breve te lo confirmamos.'], 'confirmado' => ['Confirmado', 'Está confirmado y facturado.'], 'preparando' => ['En preparación', 'Lo estamos armando.'], 'enviado' => [$p->entrega === 'envio' ? 'En camino' : 'Listo para retirar', $p->entrega === 'envio' ? 'Salió hacia tu dirección.' : 'Podés pasar a buscarlo.'], 'entregado' => ['Entregado', '¡Gracias por tu compra!'], 'cancelado' => ['Cancelado', 'Este pedido fue cancelado.']][$p->estado]; @endphp
<div class="top"><div class="in"><div><h1>{{ $cfg['nombre'] }}</h1><p>Pedido {{ $p->numeroFormateado() }} · {{ $p->created_at->format('d/m/Y H:i') }}</p></div><a class="btn s" href="/t/{{ $cfg['slug'] }}">Volver a la tienda</a></div></div>
<div class="wrap" style="display:grid;gap:14px;max-width:640px">
  <div class="{{ $p->estado === 'cancelado' ? 'bad' : 'ok' }}"><div style="font-size:18px">{{ $est[0] }}</div><div style="font-weight:400;font-size:13px">{{ $est[1] }}</div></div>
  @if ($link && $p->estado !== 'cancelado' && $p->comprobante && (float) $p->comprobante->saldo > 0.005)
    <a class="btn g" href="{{ $link }}">Pagar ahora {{ $fmt($p->comprobante->saldo) }}</a>
  @elseif ($p->comprobante && (float) $p->comprobante->saldo <= 0.005 && $p->comprobante->esFactura())
    <div class="ok">Pagado. ¡Gracias!</div>
  @elseif ($p->pago === 'transferencia' && $p->estado !== 'cancelado')
    <div class="card"><b>Pagás por transferencia</b><p class="muted" style="margin:4px 0 0">@if ($cfg['alias']) Alias: <b>{{ $cfg['alias'] }}</b> @endif @if ($cfg['cbu']) · CBU: <b>{{ $cfg['cbu'] }}</b> @endif · Titular: {{ $b->razon_social ?: $b->name }}. Mandanos el comprobante por WhatsApp.</p></div>
  @endif
  <div class="card">
    <table>
      @foreach ($p->items as $it) <tr><td>{{ $it['descripcion'] }} <span class="muted">× {{ rtrim(rtrim(number_format($it['cantidad'], 3, ',', '.'), '0'), ',') }}</span></td><td class="r">{{ $fmt($it['total']) }}</td></tr> @endforeach
      @if ((float) $p->envio > 0) <tr><td>Envío</td><td class="r">{{ $fmt($p->envio) }}</td></tr> @endif
      @if ((float) $p->descuento > 0) <tr><td>Descuento</td><td class="r">−{{ $fmt($p->descuento) }}</td></tr> @endif
      <tr><td><b>Total</b></td><td class="r"><b>{{ $fmt($p->total) }}</b></td></tr>
    </table>
    <p class="muted" style="margin:10px 0 0">{{ $p->entrega === 'envio' ? 'Envío a ' . ($p->cliente['direccion'] ?? '') : 'Retiro en el local' }} · {{ $p->cliente['nombre'] }} · {{ $p->cliente['telefono'] }}</p>
    @if ($p->comprobante) <p class="muted">Comprobante: <a href="{{ $p->comprobante->urlPublica() }}">{{ $p->comprobante->nombreTipo() }} {{ $p->comprobante->numeroFormateado() }}</a></p> @endif
  </div>
  @if ($cfg['whatsapp']) <a class="btn s" href="https://wa.me/{{ preg_replace('/\D/', '', $cfg['whatsapp']) }}?text={{ rawurlencode('Hola! Consulto por mi pedido ' . $p->numeroFormateado()) }}">Consultar por WhatsApp</a> @endif
</div>
@endsection
