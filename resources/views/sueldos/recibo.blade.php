@php $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.'); @endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Recibos de sueldo {{ $liq->periodoLabel() }}</title>
<style>
  *{box-sizing:border-box} body{font-family:Montserrat,Arial,Helvetica,sans-serif;color:#1c1a18;margin:0;padding:24px;font-size:12px}
  .hoja{max-width:760px;margin:0 auto 24px;border:1px solid #d6d1ca;border-radius:12px;overflow:hidden;page-break-after:always}
  .cab{display:flex;justify-content:space-between;padding:16px;border-bottom:1px solid #d6d1ca}
  .emp{font-size:18px;font-weight:800;color:#e4003f} .tipo{font-size:15px;font-weight:800;text-align:right}
  .sec{padding:12px 16px;border-bottom:1px solid #d6d1ca} h4{margin:0 0 6px;font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:#6f6a62}
  .datos{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px 16px}
  table{width:100%;border-collapse:collapse} th{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#6f6a62;text-align:left;padding:4px 0;border-bottom:1px solid #d6d1ca} td{padding:5px 0;border-bottom:1px solid #eee} .r{text-align:right}
  .tot{padding:14px 16px;display:flex;justify-content:space-between;align-items:center} .tot b{font-size:18px}
  .firmas{display:flex;justify-content:space-between;padding:28px 16px 16px;color:#6f6a62;font-size:11px} .firmas div{border-top:1px solid #6f6a62;width:40%;padding-top:4px;text-align:center}
  .btn{position:fixed;top:12px;right:12px;background:#e4003f;color:#fff;border:0;border-radius:999px;padding:10px 18px;font-weight:700;cursor:pointer}
  @media print{.btn{display:none} body{padding:0}}
</style>
</head>
<body>
<button class="btn" onclick="window.print()">Imprimir / PDF</button>
@foreach($items as $i)
<div class="hoja">
  <div class="cab">
    <div><div class="emp">{{ $b->razon_social ?? $b->name }}</div><div>CUIT {{ $b->cuit }} · {{ $b->phone }}</div></div>
    <div><div class="tipo">RECIBO DE HABERES</div><div class="r">{{ $liq->periodoLabel() }}</div><div class="r">Fecha de pago: {{ $liq->fecha->format('d/m/Y') }}</div></div>
  </div>
  <div class="sec"><div class="datos">
    <div><h4>Empleado</h4><b>{{ $i->empleado->nombre }}</b></div><div><h4>Legajo / CUIL</h4>{{ $i->empleado->legajo }} · {{ $i->empleado->cuil }}</div><div><h4>Ingreso</h4>{{ $i->empleado->fecha_ingreso->format('d/m/Y') }} ({{ $i->empleado->antiguedadAnios($liq->fecha) }} años)</div>
    <div><h4>Categoría</h4>{{ $i->empleado->categoria ?? '-' }}</div><div><h4>Convenio</h4>{{ $i->empleado->convenio ?? '-' }}</div><div><h4>Obra social</h4>{{ $i->empleado->obra_social ?? '-' }}</div>
    <div><h4>Básico</h4>{{ $fmt($i->empleado->sueldo_basico) }}</div><div><h4>Días / horas extra</h4>{{ $i->dias }} días · {{ (float) $i->horas_extra_50 + (float) $i->horas_extra_100 }} h</div><div><h4>Pago</h4>{{ $i->empleado->cbu ? 'Acreditación en CBU ' . $i->empleado->cbu : 'Efectivo' }}</div>
  </div></div>
  <div class="sec"><table>
    <tr><th>Concepto</th><th class="r">Remunerativo</th><th class="r">No remunerativo</th><th class="r">Deducciones</th></tr>
    @foreach(collect($i->detalle)->where('tipo', '!=', 'contribucion') as $d)
    <tr><td>{{ $d['nombre'] }}</td><td class="r">{{ $d['tipo'] === 'haber' ? $fmt($d['monto']) : '' }}</td><td class="r">{{ $d['tipo'] === 'no_remunerativo' ? $fmt($d['monto']) : '' }}</td><td class="r">{{ $d['tipo'] === 'deduccion' ? $fmt($d['monto']) : '' }}</td></tr>
    @endforeach
    <tr><td><b>Totales</b></td><td class="r"><b>{{ $fmt($i->bruto) }}</b></td><td class="r"><b>{{ $fmt($i->no_rem) }}</b></td><td class="r"><b>{{ $fmt((float) $i->deducciones + (float) $i->anticipos) }}</b></td></tr>
  </table></div>
  <div class="tot"><span>Son pesos {{ ucfirst(\Illuminate\Support\Number::spell((int) round((float) $i->neto), 'es')) }}</span><b>NETO A COBRAR {{ $fmt($i->neto) }}</b></div>
  <div class="firmas"><div>Firma del empleador</div><div>Recibí conforme · {{ $i->empleado->nombre }}</div></div>
</div>
@endforeach
</body>
</html>
