@php $r = $o->recepcion ?? []; $sem = ['verde' => '#1f9d5b', 'amarillo' => '#d99a00', 'rojo' => '#c8102e']; @endphp
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><title>Acta de recepción {{ $o->numeroFormateado() }}</title>
<style>
  body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#1c1a18} h1{font-size:16px;margin:0} .muted{color:#6f6a62} table{width:100%;border-collapse:collapse;margin-top:6px} td,th{border-bottom:1px solid #e6e2dc;padding:4px;text-align:left;vertical-align:top}
  .caja{border:1px solid #e6e2dc;border-radius:6px;padding:8px;margin-top:8px} .dot{display:inline-block;width:9px;height:9px;border-radius:9px;margin-right:4px} .fotos img{width:31%;margin:1%;border:1px solid #e6e2dc}
</style></head><body>
<table><tr><td style="border:0"><h1>{{ $b->name }}</h1><span class="muted">{{ $b->address }} {{ $b->city }} · CUIT {{ $b->cuit }}</span></td><td style="border:0;text-align:right"><b>Acta de recepción</b><br>{{ $o->numeroFormateado() }}<br>{{ $o->fecha_ingreso->format('d/m/Y') }}</td></tr></table>
<div class="caja"><b>Cliente:</b> {{ $o->clienteNombre() }} @if($o->telefonoCliente()) · {{ $o->telefonoCliente() }}@endif<br>
<b>{{ $cfg['equipo'] }}:</b> {{ $o->equipo }} {{ $o->marca_modelo }} @if($o->serie) · <b>{{ $cfg['serie'] }}:</b> {{ $o->serie }}@endif @if($o->medidor !== null) · <b>{{ $cfg['medidor'] ?? 'Medidor' }}:</b> {{ number_format((float) $o->medidor, 0, ',', '.') }}@endif
@foreach(($o->datos_equipo ?? []) as $k => $v)@if($v) · <b>{{ $k }}:</b> {{ $v }}@endif @endforeach
<br><b>Falla que reporta:</b> {{ $o->falla }}</div>
<div class="caja"><b>Estado al recibir</b>@if(!empty($r['combustible'])) · Combustible: {{ $r['combustible'] }}@endif @if(!empty($r['luces'])) · Testigos: {{ implode(', ', $r['luces']) }}@endif
  @if(!empty($r['checklist']))<table>@foreach($r['checklist'] as $k => $v)<tr><td>{{ $k }}</td><td><span class="dot" style="background:{{ $sem[$v] ?? '#999' }}"></span>{{ $v }}</td></tr>@endforeach</table>@endif
  @if(!empty($r['danios']))<p><b>Daños que ya tenía:</b></p><table>@foreach($r['danios'] as $d)<tr><td>{{ $d['zona'] }}</td><td>{{ $d['detalle'] }}</td></tr>@endforeach</table>@else<p>Sin daños a la vista.</p>@endif
  @if(!empty($r['observaciones']))<p><b>Observaciones:</b> {{ $r['observaciones'] }}</p>@endif
  @if(!empty($r['comparacion']))<p class="muted">Visita anterior {{ $r['comparacion']['ot_anterior'] }} del {{ $r['comparacion']['fecha_anterior'] }}@if($r['comparacion']['anduvo'] !== null) · anduvo {{ number_format($r['comparacion']['anduvo'], 0, ',', '.') }}@endif @if(!empty($r['comparacion']['danios_nuevos'])) · daños nuevos: {{ implode(', ', $r['comparacion']['danios_nuevos']) }}@endif</p>@endif
</div>
@if(count($fotos))<div class="fotos">@foreach($fotos as $f)<img src="{{ $f }}">@endforeach</div>@endif
<div class="caja" style="margin-top:24px">El cliente deja el equipo en las condiciones descriptas y autoriza el diagnóstico.<br><br>
@if($o->firma)<img src="{{ $o->firma }}" style="height:60px"><br>@else<br><br>______________________________<br>@endif
{{ $o->firma_nombre ?: $o->clienteNombre() }}</div>
</body></html>
