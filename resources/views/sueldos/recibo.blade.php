@php
  $fmt = fn($n) => number_format((float) $n, 2, ',', '.');
  $letras = function ($n) { $n = round((float) $n, 2); $ent = (int) floor($n); $cent = (int) round(($n - $ent) * 100); return 'SON PESOS ' . mb_strtoupper(str_replace("\u{AD}", "", \Illuminate\Support\Number::spell($ent, 'es'))) . ' CON ' . str_pad($cent, 2, '0', STR_PAD_LEFT) . '/100'; };
  $tipos = ['haber' => 'Haberes', 'no_remunerativo' => 'Haberes', 'deduccion' => 'Descuentos', 'redondeo' => 'Adicionales'];
  $grupos = \App\Models\SueldoConcepto::GRUPOS;
  $tipoLiq = ['mensual' => 'Mensual', 'sac' => 'SAC', 'final' => 'Final'][$liq->tipo] ?? $liq->tipo;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Recibos de sueldo {{ $liq->periodoLabel() }}</title>
<style>
  *{box-sizing:border-box} body{font-family:DejaVu Sans,Arial,Helvetica,sans-serif;color:#1c1a18;margin:0;padding:20px;font-size:10.5px}
  .hoja{max-width:780px;margin:0 auto 22px;border:1px solid #bdb6ad;page-break-after:always}
  .hoja:last-of-type{page-break-after:auto}
  table{width:100%;border-collapse:collapse}
  td,th{vertical-align:top}
  .cab td{padding:10px 12px}
  .emp{font-size:15px;font-weight:bold;color:{{ $marca['titulo'] }}}
  .tit{font-size:13px;font-weight:bold;text-align:right;color:{{ $marca['acento'] }}}
  .band{background:{{ $marca['color_primario'] }};color:{{ $marca['texto_primario'] }}}
  .band td{padding:3px 8px;font-size:8.5px;text-transform:uppercase;letter-spacing:.05em}
  .dat td{padding:4px 8px;border-bottom:1px solid #e1dcd5;font-size:10.5px}
  .conc th{font-size:8.5px;text-transform:uppercase;letter-spacing:.05em;text-align:left;padding:4px 8px;border-bottom:1px solid #bdb6ad;color:#5b564f}
  .conc td{padding:3px 8px;border-bottom:1px solid #eee}
  .r{text-align:right} .c{text-align:center} .mut{color:#6f6a62}
  .tot td{padding:6px 8px;border-top:1px solid #bdb6ad;font-weight:bold}
  .neto{font-size:14px;color:{{ $marca['acento'] }}}
  .costo th{font-size:8.5px;text-transform:uppercase;text-align:left;padding:3px 8px;color:#5b564f;border-bottom:1px solid #e1dcd5}
  .costo td{padding:2px 8px;font-size:9.5px}
  .firma td{padding:26px 12px 10px;font-size:9.5px;color:#5b564f}
  .linea{border-top:1px solid #5b564f;padding-top:3px;text-align:center}
  .btn{position:fixed;top:12px;right:12px;background:#e4003f;color:#fff;border:0;border-radius:999px;padding:10px 18px;font-weight:700;cursor:pointer}
  @media print{.btn{display:none} body{padding:0} .hoja{border-color:#999}}
</style>
</head>
<body>
<button class="btn" onclick="window.print()">Imprimir / PDF</button>
@foreach($items as $i)
@php
  $e = $i->empleado;
  $det = collect($i->detalle ?? []);
  $contr = $det->where('tipo', 'contribucion');
  $filas = $det->whereIn('tipo', ['haber', 'no_remunerativo', 'deduccion', 'redondeo']);
  $descuentos = (float) $i->deducciones + (float) $i->anticipos;
  $costo = (float) $i->bruto + (float) $i->no_rem + (float) $i->contribuciones;
  $fechaPago = $liq->fecha->copy()->addMonthNoOverflow()->startOfMonth()->addDays(max(1, (int) ($cfg['dia_pago'] ?? 4)) - 1);
  $depPer = $liq->deposito_periodo ? \Illuminate\Support\Carbon::parse($liq->deposito_periodo . '-01')->format('m/y') : null;
@endphp
<div class="hoja">
  <table class="cab"><tr>
    <td style="width:62%">
      @if($marca['logo_uri'])<img src="{{ $marca['logo_uri'] }}" style="max-height:40px;max-width:160px;margin-bottom:4px"><br>@endif
      <div class="emp">{{ $b->razon_social ?? $b->name }}</div>
      <div>CUIT: {{ $b->cuit }}@if($cfg['actividad']) · Actividad: {{ $cfg['actividad'] }}@endif</div>
      <div>Dirección: {{ trim(($b->address ?? '') . ' ' . ($b->city ?? '')) ?: '-' }}</div>
      <div>Convenio: {{ $cfg['convenio'] ?: ($e->convenio ?: '-') }} · Obra social: {{ $e->obra_social ?: ($cfg['obra_social'] ?: '-') }}</div>
    </td>
    <td class="r">
      <div class="tit">RECIBO DE HABERES</div>
      <div>Liquidación {{ $tipoLiq }} · <b>{{ $liq->fecha->format('m/Y') }}</b></div>
      <div class="mut">Ley 20.744, art. 140</div>
    </td>
  </tr></table>

  <table><tr class="band"><td>Legajo</td><td>Apellido y nombre</td><td>CUIL</td><td>Documento</td><td>Centro / lugar</td><td>Fecha de pago</td></tr></table>
  <table class="dat"><tr>
    <td>{{ $e->legajo }}</td><td><b>{{ $e->nombre }}</b></td><td>{{ $e->cuil }}</td><td>{{ $e->documento ? 'DU ' . $e->documento : '-' }}</td><td>{{ $e->centro_costo ?: '-' }} · {{ $e->lugar_trabajo ?: '-' }}</td><td>{{ $fechaPago->format('d/m/Y') }}</td>
  </tr></table>
  <table><tr class="band"><td>Categoría</td><td>Básico</td><td>Ingreso</td><td>Antigüedad</td><td>Jornada</td><td>Modalidad</td></tr></table>
  <table class="dat"><tr>
    <td>{{ $e->categoria ?: '-' }}</td><td>$ {{ $fmt($e->sueldo_basico) }}</td><td>{{ $e->fecha_ingreso->format('d/m/Y') }}</td><td>{{ $e->antiguedadAnios($liq->fecha) }} años</td><td>{{ $e->jornadaTexto() }}</td><td>{{ $e->activo ? 'ACTIVO' : 'BAJA' }}</td>
  </tr></table>

  <table class="conc">
    <tr><th style="width:12%">Tipo</th><th style="width:8%">Código</th><th>Concepto</th><th class="r" style="width:11%">Cantidad</th><th class="r" style="width:15%">Importe</th><th class="c" style="width:9%">Rem/NoRem</th></tr>
    @foreach($filas as $d)
    <tr>
      <td class="mut">{{ $tipos[$d['tipo']] ?? '' }}</td><td>{{ $d['codigo'] }}</td>
      <td>{{ $d['nombre'] }}@if(($d['cantidad'] ?? null) !== null && in_array($d['codigo'], ['1102', 'ANT'], true)) {{ $e->fecha_ingreso->format('d/m/y') }}@endif</td>
      <td class="r">{{ number_format((float) ($d['cantidad'] ?? 1), 2, ',', '.') }}</td>
      <td class="r">{{ $d['tipo'] === 'deduccion' ? '-' : '' }}{{ $fmt($d['monto']) }}</td>
      <td class="c">{{ ['haber' => 'Rem', 'no_remunerativo' => 'NoRem'][$d['tipo']] ?? '' }}</td>
    </tr>
    @endforeach
  </table>

  <table class="tot"><tr>
    <td>Remunerativo<br><span style="font-size:12px">$ {{ $fmt($i->bruto) }}</span></td>
    <td>No remunerativo<br><span style="font-size:12px">$ {{ $fmt($i->no_rem) }}</span></td>
    <td>Descuentos<br><span style="font-size:12px">$ {{ $fmt($descuentos) }}</span></td>
    <td class="r">SUELDO NETO<br><span class="neto">$ {{ $fmt($i->neto) }}</span></td>
  </tr><tr><td colspan="4" style="font-weight:normal;border-top:0;padding-top:0">{{ $letras($i->neto) }}</td></tr></table>

  <table class="dat"><tr>
    <td>Último depósito de aportes: {{ $liq->deposito_fecha?->format('d/m/y') ?? '-' }}{{ $liq->deposito_banco ? ', ' . $liq->deposito_banco : '' }}{{ $depPer ? ', mes ' . $depPer : '' }}</td>
    <td class="r">Pago: {{ $e->cbu ? 'acreditación en CBU ' . $e->cbu : 'efectivo' }}{{ $cfg['lugar_pago'] ? ' · ' . $cfg['lugar_pago'] : '' }}</td>
  </tr></table>

  @if($contr->isNotEmpty())
  <table><tr>
    <td style="width:55%;border-right:1px solid #e1dcd5">
      <table class="costo">
        <tr><th colspan="2">Contribuciones del empleador</th></tr>
        @foreach($contr as $d)<tr><td>{{ $d['codigo'] }} {{ $d['nombre'] }}</td><td class="r">$ {{ $fmt($d['monto']) }}</td></tr>@endforeach
        <tr><td><b>Subtotal contribuciones</b></td><td class="r"><b>$ {{ $fmt($i->contribuciones) }}</b></td></tr>
      </table>
    </td>
    <td>
      <table class="costo">
        <tr><th>Composición del costo</th><th class="r">Trabajador</th><th class="r">Empleador</th><th class="r">%</th></tr>
        <tr><td>Sueldo neto</td><td class="r">{{ $fmt($i->neto) }}</td><td></td><td class="r">{{ $costo > 0 ? number_format($i->neto / $costo * 100, 2, ',', '.') : '0' }}%</td></tr>
        @foreach($grupos as $g => $gn)
          @php $t = (float) $det->where('tipo', 'deduccion')->where('grupo', $g)->sum('monto'); $em = (float) $contr->where('grupo', $g)->sum('monto'); @endphp
          @if($t + $em > 0)<tr><td>{{ $gn }}</td><td class="r">{{ $fmt($t) }}</td><td class="r">{{ $fmt($em) }}</td><td class="r">{{ $costo > 0 ? number_format(($t + $em) / $costo * 100, 2, ',', '.') : '0' }}%</td></tr>@endif
        @endforeach
        <tr><td colspan="2"><b>Costo total empleador</b></td><td colspan="2" class="r" style="white-space:nowrap"><b>$ {{ $fmt($costo) }}</b></td></tr>
      </table>
    </td>
  </tr></table>
  @endif

  <table class="firma"><tr>
    <td style="width:50%"><div class="linea">Firma del empleador</div></td>
    <td><div class="linea">Recibí el importe neto de esta liquidación y un duplicado de este recibo · {{ $e->nombre }}</div></td>
  </tr></table>
</div>
@endforeach
</body>
</html>
