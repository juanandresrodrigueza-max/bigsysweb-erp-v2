@extends('tienda._layout', ['titulo' => $t['opinion'] . ' · ' . $cfg['nombre']])
@section('cuerpo')
<div class="top"><div class="in"><div><h1>{{ $cfg['nombre'] }}</h1><p>{{ $c->titulo() }} · {{ $c->numeroFormateado() }}</p></div></div></div>
<div class="wrap" style="max-width:520px">
  @if ($hecha)
    <div class="ok" style="text-align:center;font-size:16px">{{ $t['gracias'] }}</div>
  @else
    <form method="post" class="card" style="display:grid;gap:12px" id="op">@csrf<input type="hidden" name="puntaje" id="pt" value=""><input type="hidden" name="canal" value="{{ $canal }}">
      <h2 style="margin:0;text-align:center">{{ $t['opinion'] }}</h2>
      <div style="display:flex;justify-content:center;gap:8px;font-size:38px;cursor:pointer" id="estrellas">@for ($i = 1; $i <= 5; $i++)<span data-v="{{ $i }}" style="color:#d6d1ca" onclick="elegir({{ $i }})">★</span>@endfor</div>
      <textarea name="comentario" rows="3" placeholder="{{ $t['comentario'] }}" style="width:100%;border:1px solid #e6e2dc;border-radius:12px;padding:10px;font:inherit"></textarea>
      <button class="btn p" id="btn" disabled>{{ $t['enviar_opinion'] }}</button>
    </form>
    <script>function elegir(v){document.getElementById('pt').value=v;document.querySelectorAll('#estrellas span').forEach(s=>s.style.color=+s.dataset.v<=v?'#f5a524':'#d6d1ca');document.getElementById('btn').disabled=false}</script>
  @endif
</div>
@endsection
