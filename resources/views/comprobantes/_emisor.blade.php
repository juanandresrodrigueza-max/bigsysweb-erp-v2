{{-- Bloque del emisor: lo fiscal siempre (razón social, domicilio, condición IVA); logo y datos extra según la marca. --}}
@if($m['logo_uri'])<img src="{{ $m['logo_uri'] }}" class="logo" alt="Logo"><br>@endif
<div class="emp">{{ $b->razon_social ?: $b->name }}</div>
<div class="fiscal">
  @if($b->razon_social && $b->name !== $b->razon_social){{ $b->name }}<br>@endif
  {{ collect([$b->address ?: ($loc?->address ?? null), $b->city ?: ($loc?->city ?? null), $b->province ?: ($loc?->province ?? null)])->filter()->implode(' · ') }}<br>
  @if($b->phone || $b->email){{ collect([$b->phone ? 'Tel. ' . $b->phone : null, $b->email])->filter()->implode(' · ') }}<br>@endif
  <b>{{ $b->condicion_iva }}</b>
</div>
@if($m['lineas_extra'])<div class="extra">@foreach($m['lineas_extra'] as $l){{ $l }}@if(! $loop->last)<br>@endif @endforeach</div>@endif
