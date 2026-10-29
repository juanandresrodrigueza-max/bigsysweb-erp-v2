<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tu pedido · {{ $s['empresa'] }}</title>
<style>
  body{margin:0;background:#faf9f7;font-family:system-ui,sans-serif;color:#1c1a18} .top{background:linear-gradient(135deg,#4f3089,#a42785);color:#fff;padding:18px 16px} .top h1{margin:0;font-size:19px}
  .wrap{max-width:520px;margin:0 auto;padding:16px} .card{background:#fff;border:1px solid #e6e2dc;border-radius:16px;padding:18px;margin-bottom:12px} .big{font-size:22px;font-weight:800} .muted{color:#6f6a62;font-size:13px}
  .pasos{display:flex;gap:6px;margin-top:12px} .pasos i{flex:1;height:7px;border-radius:9px;background:#e6e2dc} .pasos i.on{background:#4f3089}
</style></head><body>
<div class="top"><h1>{{ $s['empresa'] }}</h1><p style="margin:2px 0 0;opacity:.9">Seguimiento de tu entrega</p></div>
<div class="wrap"><div class="card" id="c">
  @php $paso = ['preparando' => 1, 'en_camino' => 2, 'entregado' => 3, 'no_se_pudo' => 2][$s['estado']]; @endphp
  <p class="big">{{ ['preparando' => 'Estamos preparando tu pedido', 'en_camino' => 'Tu pedido está en camino', 'entregado' => '¡Entregado!', 'no_se_pudo' => 'No pudimos entregarlo hoy'][$s['estado']] }}</p>
  <div class="pasos"><i class="{{ $paso >= 1 ? 'on' : '' }}"></i><i class="{{ $paso >= 2 ? 'on' : '' }}"></i><i class="{{ $paso >= 3 ? 'on' : '' }}"></i></div>
  @if($s['estado'] === 'en_camino')
    <p>@if($s['paradas_antes'])Faltan <b>{{ $s['paradas_antes'] }}</b> entregas antes de la tuya.@else <b>¡Sos la próxima entrega!</b>@endif</p>
    @if($s['km'] !== null)<p>El camión está a <b>{{ number_format($s['km'], 1, ',', '.') }} km</b>@if($s['posicion']) <span class="muted">(posición {{ $s['posicion']['hace'] }})</span>@endif.</p>@endif
    @if($s['posicion'])<p><a href="https://www.google.com/maps?q={{ $s['posicion']['lat'] }},{{ $s['posicion']['lng'] }}" target="_blank">Ver por dónde viene</a></p>@endif
  @elseif($s['estado'] === 'entregado')<p class="muted">{{ $s['entregado'] }} · recibió {{ $s['recibio'] }}</p>
  @elseif($s['estado'] === 'no_se_pudo')<p class="muted">{{ $s['motivo'] }}. Te volvemos a contactar para coordinar.</p>@endif
  @if($s['repartidor'])<p class="muted">Repartidor: {{ $s['repartidor'] }}</p>@endif
</div></div>
@if($s['estado'] === 'en_camino')<script>setTimeout(() => location.reload(), 30000)</script>@endif
</body></html>
