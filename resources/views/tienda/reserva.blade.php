@extends('tienda._layout', ['titulo' => 'Reservar · ' . $cfg['nombre']])
@section('cuerpo')
<div class="top"><div class="in"><div><h1>{{ $cfg['nombre'] }}</h1><p>Reservá tu mesa. Horarios: {{ $cfg['reservas_horario'] }}</p></div>@if ($cfg['menu_activo'])<a class="btn s" href="/m/{{ $cfg['slug'] }}">Ver la carta</a>@endif</div></div>
<div class="wrap" style="max-width:560px">
  @if ($ok)
    <div class="ok"><div style="font-size:18px">¡Listo, {{ $ok['nombre'] }}!</div><div style="font-weight:400;font-size:13px">Reserva para {{ $ok['personas'] }} el {{ $ok['fecha'] }}. Te confirmamos por WhatsApp. Si no podés venir, avisanos.</div></div>
    <a class="btn s" href="/r/{{ $cfg['slug'] }}" style="margin-top:12px">Hacer otra reserva</a>
  @else
    <form method="post" class="card">
      @csrf
      @if ($errors->any()) <div class="bad">{{ $errors->first() }}</div> @endif
      <label>Nombre</label><input name="nombre" required value="{{ old('nombre') }}">
      <label>WhatsApp</label><input name="telefono" required value="{{ old('telefono') }}" placeholder="351 555 0000">
      <label>Email (opcional)</label><input name="email" type="email" value="{{ old('email') }}">
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
        <div><label>Día</label><input name="fecha" type="date" required min="{{ today()->toDateString() }}" value="{{ old('fecha', today()->toDateString()) }}"></div>
        <div><label>Hora</label><input name="hora" type="time" required value="{{ old('hora', '21:00') }}"></div>
        <div><label>Personas</label><input name="personas" type="number" min="1" max="50" required value="{{ old('personas', 2) }}"></div>
      </div>
      <label>Algo que debamos saber</label><textarea name="notas" rows="2" placeholder="Cumpleaños, silla alta, celíaco…">{{ old('notas') }}</textarea>
      <button class="btn p" style="width:100%;margin-top:14px">Reservar</button>
    </form>
  @endif
</div>
@endsection
