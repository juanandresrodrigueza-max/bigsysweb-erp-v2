@php $cant = fn($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ','); @endphp
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $oe->numeroFormateado() }} · {{ $b->name }}</title>
<style>
  *{box-sizing:border-box} body{margin:0;background:#f4f2ef;font-family:system-ui,sans-serif;color:#1c1a18;font-size:15px}
  .top{background:#1c1a18;color:#fff;padding:14px 16px;position:sticky;top:0;z-index:5} .top h1{margin:0;font-size:17px} .top p{margin:2px 0 0;font-size:12px;opacity:.8}
  .wrap{max-width:560px;margin:0 auto;padding:12px} .card{background:#fff;border-radius:14px;padding:14px;margin-bottom:10px;border:1px solid #e6e2dc}
  .ok{border-color:#1f9d5b;background:#eef9f2} .no{border-color:#c8102e;background:#fdf0f2} .muted{color:#6f6a62;font-size:12px}
  .btn{display:block;width:100%;padding:13px;border-radius:12px;border:0;font-size:15px;font-weight:700;margin-top:8px;font-family:inherit;text-align:center;text-decoration:none} .p{background:#1f9d5b;color:#fff} .s{background:#fff;color:#1c1a18;border:1px solid #d8d3cc} .r{background:#fff;color:#c8102e;border:1px solid #f0c8cf} .d{background:#4f3089;color:#fff}
  input{width:100%;padding:11px;border:1px solid #d8d3cc;border-radius:10px;font-size:15px;margin-top:6px;font-family:inherit} details summary{cursor:pointer;font-weight:600;margin-top:8px}
  .n{display:inline-flex;width:26px;height:26px;border-radius:99px;background:#1c1a18;color:#fff;align-items:center;justify-content:center;font-size:13px;margin-right:6px}
  #gps{font-size:11px;opacity:.8}
</style></head><body>
<div class="top"><h1>{{ $oe->numeroFormateado() }} · {{ $oe->repartidor ?: 'Reparto' }}</h1><p>{{ $b->name }} · {{ $oe->fecha->format('d/m') }} · {{ count($items) }} paradas · <span id="gps">{{ $oe->estado === 'en_curso' ? 'compartiendo ubicación…' : '' }}</span></p></div>
<div class="wrap">
  @if(session('ok'))<div class="card ok">{{ session('ok') }}</div>@endif
  @if($errors->any())<div class="card no">{{ $errors->first() }}</div>@endif
  @if($oe->estado === 'pendiente')
    <form method="post" action="/reparto/{{ $oe->token_chofer }}/salir">@csrf<button class="btn d">Salí: empezar el recorrido</button></form>
  @endif
  @foreach($maps as $k => $m)<a class="btn s" href="{{ $m }}" target="_blank">Abrir en Google Maps · tramo {{ $k + 1 }}</a>@endforeach
  @foreach($items as $n => $x) @php $i = $x['i']; @endphp
  <div class="card {{ $i->estado === 'entregado' ? 'ok' : ($i->estado === 'no_entregado' ? 'no' : '') }}">
    <div><span class="n">{{ $n + 1 }}</span><b>{{ $i->comprobante?->contact?->name ?? $i->contact?->name ?? $i->nombre }}</b></div>
    <p class="muted">{{ $i->direccion ?: 'sin dirección' }}@if($x['tel']) · <a href="tel:{{ $x['tel'] }}">{{ $x['tel'] }}</a>@endif</p>
    @if($x['lleva'])<p class="muted">Lleva: @foreach($x['lleva'] as $l){{ $cant($l['cantidad']) }} {{ $l['descripcion'] }}@if(!$loop->last) · @endif @endforeach</p>@endif
    @if($i->estado === 'entregado')<p><b>Entregado</b> {{ $i->entregado_en?->format('H:i') }} · recibió {{ $i->recibio }}</p>
    @elseif($i->estado === 'no_entregado')<p><b>No se pudo:</b> {{ $i->motivo }}</p>
    @else
      <details><summary>Entregué</summary>
        <form method="post" enctype="multipart/form-data" action="/reparto/{{ $oe->token_chofer }}/parada/{{ $i->id }}/entregue" class="conpos">@csrf
          <input name="recibio" placeholder="¿Quién lo recibió?" required>
          <input type="file" name="foto" accept="image/*" capture="environment">
          <input type="hidden" name="lat"><input type="hidden" name="lng">
          <button class="btn p">Confirmar entrega</button></form></details>
      <details><summary>No se pudo</summary>
        <form method="post" action="/reparto/{{ $oe->token_chofer }}/parada/{{ $i->id }}/no">@csrf
          <input name="motivo" placeholder="Cerrado, no estaba, dirección mal…" required>
          <button class="btn r">Anotar y seguir</button></form></details>
    @endif
  </div>
  @endforeach
</div>
<script>
  // Mientras la página está abierta y el viaje en curso, la ubicación se manda cada 15 segundos.
  const enCurso = @json($oe->estado === 'en_curso'); let ult = null;
  const csrf = document.querySelector('meta[name=csrf-token]').content;
  function mandar() { if (!navigator.geolocation) return; navigator.geolocation.getCurrentPosition(p => { ult = p.coords; document.querySelectorAll('.conpos input[name=lat]').forEach(e => e.value = p.coords.latitude); document.querySelectorAll('.conpos input[name=lng]').forEach(e => e.value = p.coords.longitude);
    if (enCurso) fetch('/reparto/{{ $oe->token_chofer }}/posicion', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: JSON.stringify({ lat: p.coords.latitude, lng: p.coords.longitude }) }).then(() => { document.getElementById('gps').textContent = 'ubicación enviada ' + new Date().toLocaleTimeString().slice(0, 5) }) }, () => { document.getElementById('gps').textContent = 'sin GPS' }, { enableHighAccuracy: true, timeout: 10000 }) }
  mandar(); setInterval(mandar, 15000);
</script>
</body></html>
